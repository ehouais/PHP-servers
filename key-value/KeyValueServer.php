<?php
/*
$params = array(
    "urlroot" => "http://mydomain.com/",    // URL of keyval server (mandatory)
    "datadir" => "path/to/data",            // path of data directory (mandatory)
    "cors" => array(                        // list of accepted cross-origin domains (optional)
        "myotherdomain.com",
    ),
    "accounts" => array(                    // list of valid accounts for digest authentication (optional)
        "login" => "password",
    ),
);
*/
class KeyValueServer extends HttpServer {
    private static $datadir;

    private static function filepath($id) {
        $id = str_replace("/", "_", $id);
        return self::$datadir."/".$id."-".md5($id).".val";
    }
    private static function uri($id) {
        return self::root()."/".str_replace("_", "/", $id);
    }
    private static function writeData($id, $data) {
        if (file_put_contents(self::filepath($id), $data) === false) {
            throw new Exception("Unable to write to file system");
        }
    }
    private static function checkFile($id) {
        $filepath = self::filepath($id);
        if (file_exists($filepath)) {
            return $filepath;
        } else {
            self::error404();
        }
    }
    private static function getOrHead($id, $payload) {
        $filepath = self::checkFile($id);
        header("Content-Type: text/plain; charset=utf-8");
        self::ifModifiedSince(filemtime($filepath), function() use ($payload, $filepath) {
            if ($payload) readfile($filepath);
        });
    }

    public static function execute() {
        self::setRoot(self::$params["urlroot"]);
        self::$datadir = self::$params["datadir"];

        if (isset(self::$params["cors"])) {
            self::cors(self::$params["cors"]);
        }

        if (isset(self::$params["accounts"])) {
            self::digestAuth("realm", uniqid(), self::$params["accounts"]);
        }

        self::ifMatch("", array(
            "GET" => function() {
                $uris = array();
                if ($handle = opendir(self::$datadir)) {
                    while (($entry = readdir($handle)) !== false) {
                        if (substr($entry, -4) == ".val") {
                            $uri = self::uri(substr($entry, 0, strrpos($entry, "-")));
                            $uris[$uri] = filemtime(self::$datadir."/".$entry);
                        }
                    }
                    closedir($handle);
                    arsort($uris);
                }
                self::sendAsJson(array_keys($uris));
                return true;
            },
            "POST" => function() {
                $id = uniqid();
                writeData($id, self::body());
                // return URI for newly created resource
                $uri = uri($id);
                header("HTTP/1.1 201 Created");
                header("Access-Control-Expose-Headers: location, content-location");
                header("Location: ".$uri);
                header("Content-Location: ".$uri);
                return true;
            }
        ))

        || self::ifMatch("@^(.+)@i", array(
            "GET" => function($matches) { self::getOrHead($matches[1], true); return true; },
            "HEAD" => function($matches) { self::getOrHead($matches[1], false); return true; },
            "PUT" => function($matches) {
                self::writeData($matches[1], self::body());
                header("HTTP/1.1 204 No Content");
                return true;
            },
            "DELETE" => function($matches) {
                $filepath = self::checkFile($matches[1]);
                unlink($filepath);
                header("HTTP/1.1 204 No Content");
                return true;
            }
        ))

        || self::error404();
    }
}
?>
