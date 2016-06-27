<?php
$config = array(
    // URL of keyval server
    "urlroot" => "http://mydomain.com/",

    // path of data directory
    "datadir" => "path/to/data",

    // list of accepted origin domains
    "cors" => array(
        "myotherdomain.com",
    ),

    // list of valid accounts for digest authentication
    "accounts" => array(
        "login" => "password",
    ),
);
?>
