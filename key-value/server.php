<?php
date_default_timezone_set("Europe/Paris");

include_once "config.php";
include_once "Request.php";

Request::cors($GLOBALS["config"]["cors"]);

Request::digestAuth("realm", uniqid(), $GLOBALS["config"]["accounts"]);

function filepath($id) {
    $id = str_replace("/", "_", $id);
    return $GLOBALS["config"]["datadir"]."/".$id."-".md5($id).".val";
}

function uri($id) {
    return $GLOBALS["config"]["urlroot"].str_replace("_", "/", $id);
}

function writeData($id, $data) {
    if (file_put_contents(filepath($id), $data) === false) {
        throw new Exception("Unable to write to file system");
    }
}

Request::bind("", array(
    "GET" => function() {
        header("Vary: Accept");
        if (Request::accept() == "json") {
            $uris = array();
            if ($handle = opendir($GLOBALS["config"]["datadir"])) {
                while (($entry = readdir($handle)) !== false) {
                    if (substr($entry, -4) == ".val") {
                        $uris[] = uri(substr($entry, 0, strrpos($entry, "-")));
                    }
                }
                closedir($handle);
            }
            Request::sendAsJson($uris);
        } else {
            // GUI to create new private values
            include "index.html";
        }
    },
    "POST" => function() {
        $id = uniqid();
        writeData($id, Request::body());
        // return URI for newly created resource
        $uri = uri($id);
        header("HTTP/1.1 201 Created");
        header("Access-Control-Expose-Headers: location, content-location");
        header("Location: ".$uri);
        header("Content-Location: ".$uri);
    }
));

function checkFile($id) {
    $filepath = filepath($id);
    if (file_exists($filepath)) {
        return $filepath;
    } else {
        Request::error404();
    }
}

function getOrHead($id, $payload) {
    $filepath = checkFile($id);
    header("Content-Type: text/plain; charset=utf-8");
    Request::ifModifiedSince(filemtime($filepath), function() use ($payload, $filepath) {
        if ($payload) readfile($filepath);
    });
}

Request::bind("@^(.+)@i", array(
    "GET" => function($matches) { getOrHead($matches[1], true); },
    "HEAD" => function($matches) { getOrHead($matches[1], false); },
    "PUT" => function($matches) {
        writeData($matches[1], Request::body());
        header("HTTP/1.1 204 No Content");
    },
    "DELETE" => function($matches) {
        $filepath = checkFile($matches[1]);
        unlink($filepath);
        header("HTTP/1.1 204 No Content");
    }
));

Request::error404();
?>
