<?php

function php_die($msg, $status=1)
{
    echo $msg;
    exit($status);
}
