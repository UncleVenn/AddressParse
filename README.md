# AddressParse
用于地址解析的类库

使用方法:
(new AddressParse())->parse('填写的地址')


可以将一段较为复杂的地址解析出来
例如:北京市朝阳区富康路姚家园3号楼5单元3305室马云15000000000邮编038300
结果:
Array
(
    [name] => 马云
    [mobile] => 15000000000
    [detail] => 
    [zip_code] => 038300
    [phone] => 
    [province] => 北京
    [city] => 北京
    [area] => 朝阳区
    [addr] => 富康路姚家园3号楼5单元3305室
    [result] => 
)
