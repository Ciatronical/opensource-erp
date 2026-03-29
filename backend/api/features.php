<?php
$lxCars = false;
if(isset($data['features'])) {
    $lxCars = in_array('lxcars', $data['features']);
}