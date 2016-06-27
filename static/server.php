<?php
include "config.php";
include "Request.php";

Request::cors($GLOBALS["config"]["cors"]);

Request::bindToFiles("@^(.+)$@", $GLOBALS["config"]["resdir"]);

Request::error404();
?>
