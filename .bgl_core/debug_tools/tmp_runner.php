<?php
$file='tests/Unit/tmp_alias_trait.php';
$params=json_encode(['old_name'=>'Foo\\Bar','new_name'=>'Baz\\Qux']);
$vendor=__DIR__.'/../vendor';
$argv=[0,$file,'rename_reference',$params,$vendor];
include __DIR__.'/../.bgl_core/actuators/patcher.php';
