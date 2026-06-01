<?php

$env = parse_ini_file(__DIR__ . "/../.env");

define("TMDB_TOKEN", $env["TMDB_TOKEN"]);