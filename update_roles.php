<?php
$dir = new RecursiveDirectoryIterator('pages/admin');
$ite = new RecursiveIteratorIterator($dir);
foreach($ite as $file) {
    if(pathinfo($file->getFilename(), PATHINFO_EXTENSION) == 'php') {
        $c = file_get_contents($file->getPathname());
        $c = str_replace("require_roles(['admin'])", "require_roles(['platform_admin', 'gym_owner'])", $c);
        $c = str_replace("require_roles(['admin', 'member'])", "require_roles(['platform_admin', 'gym_owner', 'member'])", $c);
        $c = str_replace("require_roles(['admin', 'trainer'])", "require_roles(['platform_admin', 'gym_owner', 'trainer'])", $c);
        file_put_contents($file->getPathname(), $c);
    }
}
echo "Done";
