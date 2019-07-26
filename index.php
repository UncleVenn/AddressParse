<?php
error_reporting(0);
header("Content-type:text/html;charset=utf-8");
require './AddressParse.php';
$address = '北京市朝阳区富康路姚家园3号楼5单元3305室马云15000000000邮编038300';
echo $address;
echo '<pre>';
print_r((new AddressParse())->parse($address));