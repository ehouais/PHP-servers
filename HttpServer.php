<?php
interface iServer {
    static function run($params);
}

class HttpException extends Exception {
    public function __construct($message, $code = 500, Exception $previous = null) {
        parent::__construct($message, $code, $previous);
    }
}

abstract class HttpServer {
    private static $urlroot;
    protected static $params;

    // Replaces "\uxxxx" sequences by true UTF-8 multibyte characters
    protected static function unicodeSeqtoMb($str) {
        return html_entity_decode(preg_replace("/\\\u([0-9a-f]{4})/", "&#x\\1;", $str), ENT_NOQUOTES, 'UTF-8');
    }
    protected static function toJson($data) {
        // if PHP version >= 5.4.0, piece of cake
        if (defined('JSON_UNESCAPED_SLASHES')) return json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        // else first JSON_UNESCAPED_SLASHES polyfill...
        $data = str_replace("\/", "/", json_encode($data));
        // ...then JSON_UNESCAPED_UNICODE polyfill
        return self::unicodeSeqtoMb($data);
    }
    // Function picked from http://stackoverflow.com/questions/6054033/pretty-printing-json-with-php
    protected static function prettifyJson($json) {
        $result = '';
        $level = 0;
        $prev_char = '';
        $in_quotes = false;
        $ends_line_level = NULL;
        $json_length = strlen($json);

        for ($i = 0; $i < $json_length; $i++) {
            $char = $json[$i];
            $new_line_level = NULL;
            $post = "";
            if ($ends_line_level !== NULL) {
                $new_line_level = $ends_line_level;
                $ends_line_level = NULL;
            }
            if ($char === '"' && $prev_char != '\\') {
                $in_quotes = !$in_quotes;
            } else if (!$in_quotes) {
                switch ($char) {
                case '}': case ']':
                    $level--;
                    $ends_line_level = NULL;
                    $new_line_level = $level;
                    break;

                case '{': case '[':
                    $level++;
                case ',':
                    $ends_line_level = $level;
                    break;

                case ':':
                    $post = " ";
                    break;

                case " ": case "\t": case "\n": case "\r":
                    $char = "";
                    $ends_line_level = $new_line_level;
                    $new_line_level = NULL;
                    break;
                }
            }
            if ($new_line_level !== NULL) {
                $result .= "\n".str_repeat("    ", $new_line_level);
            }
            $result .= $char.$post;
            $prev_char = $char;
        }

        return $result;
    }
    protected static function removeTrailingSlash($str) {
        return substr($str, -1) == "/" ? substr($str, 0, -1) : $str;
    }

    protected static function method() {
        return strtoupper($_SERVER["REQUEST_METHOD"]);
    }
    protected static function accept() {
        if (isset($_SERVER["HTTP_ACCEPT"])) {
            if (strpos($_SERVER["HTTP_ACCEPT"], "application/json") !== false) {
                return "json";
            } elseif (strpos($_SERVER["HTTP_ACCEPT"], "text/html") !== false) {
                return "html";
            }
        }
        return "any";
    }
    protected static function body() {
        return file_get_contents("php://input");
    }
    protected static function setRoot($urlroot) {
        // Remove urlroot trailing slash, if any
        self::$urlroot = substr($urlroot, -1) == "/" ? substr($urlroot, 0, -1) : $urlroot;
    }
    protected static function root() {
        return self::$urlroot;
    }
    protected static function path() {
        // Parse full request URI
        // I still don't know why some installs generate the $_SERVER["SCRIPT_URI"] entry and others don't...
        $root = $_SERVER["REQUEST_SCHEME"]."://".$_SERVER["HTTP_HOST"].($_SERVER["SERVER_PORT"] != "80" ? $_SERVER["SERVER_PORT"] : "");
        $uri = parse_url($root.$_SERVER["REQUEST_URI"]);

        // path = uri - urlroot
        $path = self::$urlroot ? str_replace(self::$urlroot, "", $root.$uri["path"]) : $uri["path"];

        // Remove trailing slash, if any
        if (substr($path, -1) == "/") $path = substr($path, 0, -1);

        return $path;
    }
    protected static function error($code, $msg = "") {
        throw new HttpException($msg, $code);
    }
    protected static function error400($msg = "") {
        self::error(400, $msg);
    }
    protected static function error401($type, $realm, $msg = "", $nonce = "") {
        header("WWW-Authenticate: ".$type." realm=\"".$realm."\"".($nonce ? ",qop=\"auth\",nonce=\"".$nonce."\",opaque=\"".md5($realm)."\"" : ""));
        self::error(401, $msg);
    }
    protected static function error404() {
        self::error(404);
    }
    protected static function error405() {
        self::error(405);
    }
    protected static function error500($msg = "") {
        self::error(500, $msg);
    }
    protected static function cors($allowed) {
        if (isset($_SERVER["HTTP_ORIGIN"])) {
            header("Vary: Origin", false);
            $domain = str_replace("http://", "", $_SERVER['HTTP_ORIGIN']);
            foreach ($allowed as $pattern) {
                if (preg_match("/".str_replace(array(".", "*"), array("\.", "[^\.]+"), $pattern)."/", $domain, $matches)) {
                    header("Access-Control-Allow-Origin: ".$_SERVER['HTTP_ORIGIN']);
                    header("Access-Control-Allow-Credentials: true");
                    break;
                }
            }
        }
        if (self::method() == "OPTIONS") {
            if (isset($_SERVER["HTTP_ACCESS_CONTROL_REQUEST_METHOD"]))
                header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");

            if (isset($_SERVER["HTTP_ACCESS_CONTROL_REQUEST_HEADERS"]))
                header("Access-Control-Allow-Headers: ".$_SERVER["HTTP_ACCESS_CONTROL_REQUEST_HEADERS"]);

            exit;
        }
    }
    protected static function ifModifiedSince($timestamp, $cb) {
        if (isset($_SERVER["HTTP_IF_MODIFIED_SINCE"]) && strtotime($_SERVER["HTTP_IF_MODIFIED_SINCE"]) >= $timestamp) {
            header("HTTP/1.0 304 Not Modified");
        } else {
            header("HTTP/1.1 200 OK");
            header("Last-Modified: ".gmdate("D, d M Y H:i:s", $timestamp)." GMT");
            $cb();
        }
    }
    protected static function ifMatch($pattern, $handlers, $context = null) {
        $method = self::method();

        // Ignore initial slash in path
        $path = substr(self::path(), 1);

        if (!is_array($handlers)) {
            $handlers = array("GET" => $handlers);
        }
        $matches = null;
        if (($pattern == "" && $path == "") || ($pattern && preg_match($pattern, $path, $matches))) {
            $found = null;

            foreach ($handlers as $methods => $handler) {
                $methods = explode(",", strtoupper(str_replace(" ", "", $methods)));
                if (in_array($method, $methods) || ($method != "OPTIONS" && in_array("*", $methods))) {
                    $found = $handler;
                    break;
                }
            }

            if ($found) {
                if (is_callable($found)) {
                    return $found($matches);
                } else {
                    if ($context) extract($context);
                    if (file_exists($found)) {
                        include $found;
                        return true;
                    }
                }
            }
        }
    }
    protected static function sendFile($filepath) {
        $finfo = finfo_open(FILEINFO_MIME);
        header("Content-type: ".finfo_file($finfo, $filepath));
        finfo_close($finfo);
        readfile($filepath);
    }
    protected static function sendJson($json) {
        header("Vary: Accept", false);
        $json = self::unicodeSeqtoMb($json);
        if (self::accept() == "html") {
            $json = self::prettifyJson($json);
            $json = str_replace(array("<", ">"), array("&lt;", "&gt;"), $json);
            header("Content-Type: text/html; charset=utf-8");
            print "<meta charset=\"utf-8\">";
            print "<pre>".preg_replace("/\"(https?:\/\/[^\"]+)\"/", "<a href=\"$1\">$1</a>", $json)."</pre>";
        } else {
            header("Content-Type: application/json; charset=utf-8");
            print $json;
        }
    }
    protected static function sendAsJson($data) {
        self::sendJson(self::toJson($data));
    }
    protected static function sendCollectionItemAsJson($list, $key) {
        if (!isset($list[$key])) self::error404();
        self::sendAsJson($list[$key]);
    }
    protected static function basicAuth($realm, $validate) {
        if (isset($_SERVER["PHP_AUTH_USER"])) {
            $login = $_SERVER["PHP_AUTH_USER"];
            if ($validate($login, $_SERVER["PHP_AUTH_PW"])) {
                return $login;
            }
        }

        self::error401("Basic", $realm, "You need to enter a valid username and password.");
    }
    protected static function digestAuth($realm, $nonce, $credentials) {
        $auth = false;

        // Get the digest string from http header
        if (isset($_SERVER["PHP_AUTH_DIGEST"])) {
            // mod_php
            $digestStr = $_SERVER["PHP_AUTH_DIGEST"];
        } elseif (isset($_SERVER["HTTP_AUTHENTICATION"]) && strpos(strtolower($_SERVER["HTTP_AUTHENTICATION"]), "digest") === 0) {
            // most other servers
            $digestStr = substr($_SERVER["HTTP_AUTHORIZATION"], 7);
        }

        if (!is_null($digestStr)) {
            // Extract digest elements from the string
            $digest = array();
            preg_match_all('@(\w+)=(?:(?:")([^"]+)"|([^\s,$]+))@', $digestStr, $matches, PREG_SET_ORDER);
            foreach ($matches as $m) {
                $digest[$m[1]] = $m[2] ? $m[2] : $m[3];
            }

            // If provided digest is complete
            if (isset($digest["uri"]) && isset($digest["nonce"]) && isset($digest["nc"]) && isset($digest["cnonce"]) && isset($digest["qop"]) && isset($digest["response"])) {
                // Compare user response with each of valid responses
                foreach ($credentials as $login => $password) {
                    $A1 = md5("{$login}:{$realm}:{$password}");
                    $A2 = md5("{$_SERVER["REQUEST_METHOD"]}:{$digest["uri"]}");
                    $response = md5("{$A1}:{$digest["nonce"]}:{$digest["nc"]}:{$digest["cnonce"]}:{$digest["qop"]}:{$A2}");
                    if ($response == $digest["response"]) {
                        return $login;
                    }
                }
            }
        }

        self::error401("Digest", $realm, "You need to enter a valid username and password.", $nonce);
    }

    private static function sendError($code, $msg = "") {
        switch($code) {
        case 400: header("HTTP/1.1 400 Bad Request"); break;
        case 401: header("HTTP/1.1 401 Not Authorized"); break;
        case 404: header("HTTP/1.1 404 Not Found"); break;
        case 405: header("HTTP/1.1 405 Method Not Allowed"); break;
        case 422: header("HTTP/1.1 422 Unprocessable Entity"); break;
        case 500: header("HTTP/1.1 500 Internal Server Error"); break;
        }
        print $msg;
    }
    final public static function run($params) {
        self::$params = $params;

        try {
            ob_start();
            static::execute();
            ob_end_flush();
        } catch (HttpException $e) {
            ob_end_clean();
            self::sendError($e->getCode(), $e->getMessage());
        } catch (Exception $e) {
            ob_end_clean();
            self::sendError(500);
        }
    }
}
?>
