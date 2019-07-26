<?php
class AddressParse {
    private $defaultData = [];

    private $zipCodeList = [];

    private $mCity;

    private $mArea;

    function __construct() {
        $list = require './arealist.php';
        $this->defaultData = json_decode($list['area'], true);
        $this->zipCodeFormat(json_decode($list['zipCode'], true));
        $this->parseArea();
    }
    // 处理原始地址数据转换成专用数据
    private function parseArea() {
        $list = $this->defaultData;
        if (empty($list)) {
            return true;
        }
        foreach ($list as $province) {
            if ($province['city']) {
                foreach ($province['city'] as $city) {
                    if ($city['name'] !== '其他') {
                        if (!isset($this->mCity[$city['name']])) {
                            $this->mCity[$city['name']] = [];
                        }
                        $this->mCity[$city['name']][] = [
                            'p' => $province['name'],
                            'c' => $city['name'],
                            'a' => $city['area'] || [],
                        ];
                    }
                    if ($city['area']) {
                        foreach ($city['area'] as $area) {
                            if ($area !== '其他') {
                                if (!isset($this->mArea[$area])) {
                                    $this->mArea[$area] = [];
                                }
                                $this->mArea[$area][] = [
                                    'p' => $province['name'],
                                    'c' => $city['name'],
                                ];
                            }
                        }
                    }
                }
            }
        }
    }
    // 解析邮编
    private function zipCodeFormat($zipCode) {
        $list = [];
        foreach ($zipCode as $el) {
            if ($el['child']) {
                foreach ($el['child'] as $event) {
                    if ($event['child']) {
                        foreach ($event['child'] as $element) {
                            $list[] = $element['zipcode'];
                        }
                    }
                }
            }
        }
        $this->zipCodeList = $list;
    }

    public function parse($address = '') {
        $parse = [
            'name' => '',
            'mobile' => '',
            'detail' => '',
            'zip_code' => '',
            'phone' => '',
        ];
        //去除空格
        $patterns = [
            '/\r\n/',
            '/\n/',
            '/\t/',
            '/\s+/',
        ];
        $address = preg_replace($patterns, ' ', $address);

        //自定义去除关键字，可自行添加
        $search = ['地址', '收货地址', '收货人', '收件人', '收货', '邮编', '电话', '：', ':', '；', ';', '，', ',', '。'];
        foreach ($search as $str) {
            $address = str_replace($str, ' ', $address);
        }

        //多个空格replace为一个
        $address = preg_replace('/ {2,}/', ' ', $address);
        //整理手机格式
        $address = preg_replace('/(\d{3})-(\d{4})-(\d{4})/', '$1$2$3', $address);
        $mobileReg = '/(86-[1][0-9]{10})|(86[1][0-9]{10})|([1][0-9]{10})/';

        preg_match($mobileReg, $address, $mobile);
        if ($mobile) {
            $parse['mobile'] = $mobile[0];
            $address = str_replace($mobile[0], ' ', $address);
        }

        //电话
        $phoneReg = '/(([0-9]{3,4}-)[0-9]{7,8})|([0-9]{12})|([0-9]{11})|([0-9]{10})|([0-9]{9})|([0-9]{8})|([0-9]{7})/';
        preg_match($phoneReg, $address, $phone);
        if ($phone) {
            $parse['phone'] = $phone[0];
            $address = str_replace($phone[0], ' ', $address);
        }
        //邮编(加入门牌号；考虑到重复邮编问题；去除之前简单的六位数字校验)
        $zipCodeList = $this->zipCodeList;
        foreach ($zipCodeList as $code) {
            if (mb_strpos($address, $code) !== false) {
                $parse['zip_code'] = $code;
                $address = str_replace($code, '', $address);
            }
        }
        $address = str_replace('/ {2,}/', ' ', $address);
        $detail = $this->detail_parse_forward(trim($address));
        if (!$detail['city']) {
            $detail = $this->detail_parse(trim($address));
            if ($detail['area'] && !$detail['city']) {
                $detail = $this->detail_parse(trim($address, true));
            }
            //
            $list = str_replace($detail['province'], '', $address);
            $list = str_replace($detail['city'], '', $address);
            $list = str_replace($detail['area'], '', $address);
            $list = array_filter(explode(' ', $list));
            //
            //详细住址划分关键字
            //注意：只需要填写关键字最后一位即可：比如单元填写元即可！
            $address_detail_list = ['室', '楼', '元', '号', '幢', '门', '户'];
            if (count($list) > 1) {
                foreach ($list as $str) {
                    if (!$parse['name'] || $str && strlen($str) < strlen($parse['name'])) {
                        $parse['name'] = trim($str);
                    }
                }
                if ($parse['name']) {
                    $detail['addr'] = trim(str_replace($detail['addr'], '', $parse['name']));
                }
            } else {
                //若名字写在详细地址后面，根据address_detail_list进行分割；
                $key = [];
                foreach ($address_detail_list as $el) {
                    $key[] = mb_strpos($detail['addr'], $el);
                }
                rsort($key);
                $max = $key[0];
                if ($max !== false) {
                    $addrBuild = mb_substr($detail['addr'], 0, $max + 1);
                    $addrNum = preg_replace('/[^0-9]+/', '', str_replace($addrBuild, '', $detail['addr']));
                    $userName = str_replace($addrBuild . $addrNum, '', $detail['addr']);
                    $detail['addr'] = $addrBuild . $addrNum;
                    $parse['name'] = $userName;
                }
            }
        } else {
            if ($detail['name']) {
                $parse['name'] = $detail['name'];
            } else {
                $list = array_filter(explode(' ', $detail['addr']));
                if (count($list) > 1) {
                    $parse['name'] = end($list);
                }
                if ($parse['name']) {
                    $detail['addr'] = trim(str_replace($parse['name'], '', $detail['addr']));
                }
            }
        }
        $parse['province'] = $detail['province'];
        $parse['city'] = $detail['city'];
        $parse['area'] = $detail['area'];
        $parse['addr'] = $detail['addr'];
        $parse['result'] = $detail['result'];
        return $parse;
    }
    /**
     * 正向解析模式
     * 从前到后按 province city addr 逐级筛选
     * 有city的值即可说明解析成功
     * 此模式对地址顺序有要求
     * @param address
     * @returns {{province: string, city: string, area: string, addr: string}}
     */
    private function detail_parse_forward($address) {
        $parse = [
            'province' => '',
            'city' => '',
            'area' => '',
            'addr' => '',
            'name' => '',
        ];
        $provinceKey = ['特别行政区', '古自治区', '维吾尔自治区', '壮族自治区', '回族自治区', '自治区', '省省直辖', '省', '市'];
        $cityKey = ['布依族苗族自治州', '苗族侗族自治州', '自治州', '州', '市', '县'];
        $defaultData = $this->defaultData;
        foreach ($defaultData as $province) {
            $index = mb_strpos($address, $province['name']);
            if ($index !== false) {
                if ($index > 0) {
                    $parse['name'] = $province['name'];
                }
                $parse['province'] = $province['name'];
                $address = mb_substr($address, $index + strlen($province['name']));
                foreach ($provinceKey as $pk) {
                    if (mb_strpos($address, $pk) === 0) {
                        $address = mb_substr($address, strlen($pk));
                    }
                }
                foreach ($province['city'] as $city) {
                    $index = mb_strpos($address, $city['name']);
                    if ($index !== false && $index < 3) {
                        $parse['city'] = $city['name'];
                        $address = mb_substr($address, $index + strlen($city['name']));
                        foreach ($cityKey as $ck) {
                            if (mb_strpos($address, $ck) === 0) {
                                $address = mb_substr($address, strlen($ck));
                            }
                        }
                        if ($city['area']) {
                            foreach ($city['area'] as $area) {
                                $index = mb_strpos($address, $area);
                                if ($index !== false && $index < 3) {
                                    $parse['area'] = $area;
                                    $address = mb_substr($address, $index + strlen($area));
                                    break;
                                }
                            }
                        }
                        break;
                    }
                }
                $parse['addr'] = trim($address);
                break;
            }
        }
        return $parse;
    }
/**
 * 逆向解析 从后【县，区，旗】往前解析
 * 有地区就能大概返回地址了
 * @param address
 * @param ignoreArea 是否忽视区 因为地址中含有区容易导致匹配错误 例：山东省蓬莱市黄海花园东区西门宝威学堂 曲荣声收15753572456
 * @returns {{province: string, city: string, area: string, name: string, _area: string, addr: string}}
 */
    private function detail_parse($address, $ignoreArea = false) {
        $parse = [
            'province' => '',
            'city' => '',
            'area' => '',
            'name' => '',
            '_area' => '',
            'addr' => '',
        ];
        $areaIndex = -1;
        $cityIndex = -1;
        $address = str_replace(' ', '  ', $address);
        if (!$ignoreArea && mb_strpos($address, '县') !== false ||
            !$ignoreArea && mb_strpos($address, '区') !== false ||
            !$ignoreArea && mb_strpos($address, '旗') !== false) {
            if (mb_strpos($address, '旗') !== false) {
                $parse['area'] = mb_substr($address, mb_strpos($address, '旗') - 1, 2);
            }
            if (mb_strpos($address, '区') !== false) {
                $areaIndex = mb_strpos($address, '区');

                if (mb_strrpos($address, '市', $areaIndex) !== false) {
                    $cityIndex = mb_mb_strrpos($address, '市', $areaIndex);
                    $parse['area'] = mb_substr($address, $cityIndex + 1, $areaIndex - $cityIndex);
                } else {
                    $parse['area'] = mb_substr($address, $areaIndex - 2, 3);
                }
            }
            if (mb_strpos($address, '县') !== false) {
                $areaIndex = mb_strrpos($address, '区');
                if (mb_strrpos($address, '市', $areaIndex) !== false) {
                    $cityIndex = mb_strrpos($address, '市', $areaIndex);
                    $parse['area'] = mb_substr($address, $cityIndex + 1, $areaIndex - $cityIndex);
                } else {
                    $parse['area'] = mb_substr($address, $areaIndex - 2, 3);
                }
            }
            $parse['addr'] = mb_substr($address, $areaIndex + 1);
        } else {
            if (mb_strpos($address, '市') !== false) {
                $areaIndex = mb_strpos($address, '市');
                $parse['area'] = mb_substr($address, $areaIndex - 2, 3);
                $parse['addr'] = mb_substr($address, $areaIndex + 1);
            } else {
                $parse['addr'] = $address;
            }
        }
        if (mb_strpos($address, '市') !== false ||
            mb_strpos($address, '盟') !== false ||
            mb_strpos($address, '州') !== false) {
            if (mb_strpos($address, '市') !== false) {
                $parse['_area'] = mb_substr($address, mb_strpos($address, '市') - 2, 2);
            }
            if (mb_strpos($address, '盟') !== false) {
                $parse['_area'] = mb_substr($address, mb_strpos($address, '盟') - 2, 2);
            }
            if (mb_strpos($address, '州') !== false) {
                $parse['_area'] = mb_substr($address, mb_strpos($address, '州') - 2, 2);
            }
        }
        $parse['area'] = trim($parse['area']);
        $mArea = $this->mArea;
        if ($parse['area'] && $mArea[$parse['area']]) {
            if (strlen($mArea[$parse['area']]) === 1) {
                $parse['province'] = $mArea[$parse['area']][0]['p'];
                $parse['city'] = $mArea[$parse['area']][0]['c'];
            } else {
                $parse['_area'] = trim($parse['_area']);
                $addr = mb_substr($address, 0, $areaIndex);
                foreach ($mArea[$parse['area']] as $item) {
                    if (mb_strpos($address, $item['p']) !== false || $item['c'] === $parse['_area']) {
                        $d = $item;
                        break;
                    }
                }
                if ($d) {
                    $parse['province'] = $d['p'];
                    $parse['city'] = $d['c'];
                } else {
                    $parse['result'] = $mArea[$parse['area']];
                }
            }
        } else {
            if ($parse['_area']) {
                $city = $this->mCity[$parse['_area']];
                if ($city) {
                    $parse['province'] = $city[0]['p'];
                    $parse['city'] = $city[0]['c'];
                    $parse['addr'] = mb_substr($address, mb_strpos($address, $parse['city']) + strlen($parse['city']) + 1);
                    $parse['area'] = '';
                    foreach ($city[0]['a'] as $c) {
                        if (mb_strpos($parse['addr'], $c) === 0) {
                            $parse['area'] = $c;
                            $parse['addr'] = str_replace($c, '', $parse['addr']);
                            break;
                        }
                    }
                }
            } else {
                $parse['area'] = '';
            }
        }
        $parse['addr'] = trim($parse['addr']);
        return $parse;
    }

}
