<?php

namespace ExeQue\ZipStream;

if (!function_exists(__NAMESPACE__ . '\\connection_aborted')) {
    function connection_aborted(): bool
    {
        return $GLOBALS['__zipstream_connection_aborted_override'] ?? \connection_aborted();
    }
}
