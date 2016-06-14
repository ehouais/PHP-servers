<?php
class Request {
    // Replaces "\uxxxx" sequences by true UTF-8 multibyte characters
    private static function unicodeSeqtoMb($str) {
        return html_entity_decode(preg_replace("/\\\u([0-9a-f]{4})/", "&#x\\1;", $str), ENT_NOQUOTES, 'UTF-8');
    }
    private static function toJson($data) {
        // if PHP version >= 5.4.0, piece of cake
        if (defined('JSON_UNESCAPED_SLASHES')) return json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        // else first JSON_UNESCAPED_SLASHES polyfill...
        $data = str_replace("\/", "/", json_encode($data));
        /// ...then JSON_UNESCAPED_UNICODE polyfill
        return self::unicodeSeqtoMb($data);
    }
    private static function prettifyJson($json) {
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

    public static function method() {
        return strtoupper($_SERVER["REQUEST_METHOD"]);
    }
    public static function accept() {
        if (isset($_SERVER["HTTP_ACCEPT"])) {
            if (strpos($_SERVER["HTTP_ACCEPT"], "application/json") !== false) {
                return "json";
            } elseif (strpos($_SERVER["HTTP_ACCEPT"], "*/*") !== false || strpos($_SERVER["HTTP_ACCEPT"], "text/html") !== false) {
                return "html";
            }
        }
        return "any";
    }
    public static function body() {
        return file_get_contents("php://input");
    }
    public static function root() {
        return "http://".$_SERVER["SERVER_NAME"];
    }
    public static function error($header, $msg) {
        header($header);
        print $msg;
        exit;
    }
    public static function error400($msg) {
        self::error("HTTP/1.1 400 Bad Request", $msg);
    }
    public static function error401($type, $realm, $msg, $nonce = "") {
        header("WWW-Authenticate: ".$type." realm=\"".$realm."\"".($nonce ? ",qop=\"auth\",nonce=\"".$nonce."\",opaque=\"".md5($realm)."\"" : ""));
        self::error("HTTP/1.1 401 Not Authorized", $msg);
    }
    public static function error404() {
        self::error("HTTP/1.1 404 Not Found", "");
    }
    public static function error500($msg) {
        self::error("HTTP/1.1 500 Internal Server Error", $msg);
    }
    public static function cors($allowed) {
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
    public static function ifModifiedSince($timestamp, $cb) {
        if (isset($_SERVER["HTTP_IF_MODIFIED_SINCE"]) && strtotime($_SERVER["HTTP_IF_MODIFIED_SINCE"]) >= $timestamp) {
            header("HTTP/1.0 304 Not Modified");
        } else {
            header("HTTP/1.1 200 OK");
            header("Last-Modified: ".gmdate("D, d M Y H:i:s", $timestamp)." GMT");
            $cb();
        }
    }
    public static function bind($pattern, $handlers, $context = null) {
        $method = self::method();
        $path = parse_url(substr($_SERVER["REQUEST_URI"], 1), PHP_URL_PATH);
        if (substr($path, -1) == "/") $path = substr($path, 0, -1);

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
                try {
                    ob_start();
                    if (is_callable($found)) {
                        $found($matches);
                    } else {
                        if ($context) extract($context);
                        include $handler;
                    }
                    ob_end_flush();
                } catch (Exception $e) {
                    ob_end_clean();
                    self::error500($e->getMessage());
                }
                exit;
            } elseif ($method != "OPTIONS") {
                self::error("HTTP/1.1 405 Method Not Allowed", "");
            }
        }
    }
    private static $types = array(
        "js" => "application/javascript",
        "html" => "text/html",
        "css" => "text/css",
        "png" => "image/png",
        "jpg" => "image/jpg",
        "ttf" =>  "application/font-woff",
        "woff" => "application/font-woff",
        "woff2" => "application/font-woff2",
        "eot" => "application/vnd.ms-fontobject",
        "pdf" => "application/pdf",
    );
    public static function bindToFiles($pattern, $dirpath) {
        $class = __CLASS__; // TODO: use Closure::bind if PHP >= 5.4
        $types = self::$types;
        self::bind($pattern, function($matches) use ($dirpath, $class, $types) {
            $filepath = $dirpath."/".$matches[1];
            if (!file_exists($filepath)) Request::error404();

            $ext = strtolower(pathinfo($filepath, PATHINFO_EXTENSION));
            if (isset($types[$ext])) header("Content-type: ".$types[$ext]);
            $class::ifModifiedSince(filemtime($filepath), function() use ($filepath) {
                readfile($filepath);
            });
        });
    }
    public static function sendJson($json) {
        header("Vary: Accept", false);
        $json = self::unicodeSeqtoMb($json);
        if (self::accept() == "html") {
            header("Content-Type: text/html; charset=utf-8");
            print "<meta charset=\"utf-8\">";
            print "<pre>".preg_replace("/\"(https?:\/\/[^\"]+)\"/", "<a href=\"$1\">$1</a>", self::prettifyJson($json))."</pre>";
        } else {
            header("Content-Type: application/json; charset=utf-8");
            print $json;
        }
    }
    public static function sendAsJson($data) {
        self::sendJson(self::toJson($data));
    }
    // Filter $list, only keeping items matching query parameters ?propname1=value1&propname2=value2&...
    public static function filter($list, $propnames) {
        foreach($list as $id => $data) {
            foreach($propnames as $name) {
                if (isset($_GET[$name]) && isset($data[$name]) && $data[$name] != $_GET[$name]) {
                    unset($list[$id]);
                    break;
                }
            }
        }
        return $list;
    }
    // Filter $list, only keeping item properties listed in query parameters ?props=propname1,propname2,...
    public static function filterProperties($list) {
        if (isset($_GET["props"])) {
            $props = explode(",", $_GET["props"]);
            foreach($list as $key => $item) {
                $fitem = array();
                foreach($props as $name) {
                    if (isset($item[$name])) $fitem[$name] = $item[$name];
                }
                $list[$key] = $fitem;
            }
        }
        return $list;
    }
    public static function basicAuth($realm, $validate) {
        if (isset($_SERVER["PHP_AUTH_USER"])) {
            $login = $_SERVER["PHP_AUTH_USER"];
            if ($validate($login, $_SERVER["PHP_AUTH_PW"])) {
                return $login;
            }
        }

        self::error401("Basic", $realm, "You need to enter a valid username and password.");
    }
    public static function digestAuth($realm, $nonce, $credentials) {
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
}
?>
