<?php
echo "FileInfo Status: " . (extension_loaded('fileinfo') ? "ACTIVE ✅" : "INACTIVE ❌") . "\n";
echo "Loaded INI: " . php_ini_loaded_file() . "\n";
