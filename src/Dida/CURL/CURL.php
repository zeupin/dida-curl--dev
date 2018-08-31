<?php
/**
 * Dida Framework  -- A Rapid Development Framework
 * Copyright (c) Zeupin LLC. (http://zeupin.com)
 *
 * Licensed under The MIT License.
 * Redistributions of files must retain the above copyright notice.
 */

namespace Dida\CURL;

/**
 * CURL
 */
class CURL
{
    /**
     * 版本号
     */
    const VERSION = '20180901';

    /**
     * 错误列表
     */
    const ERR_INVALID_METHOD = -1;      // method无效，目前仅限Restful风格支持的几种

    /**
     * 默认的提交方式。
     * 可设置为$valid_methods支持的几种类型，用大写
     *
     * @var string
     */
    public $method = "GET";
    public $valid_methods = ["GET", "POST"];

    /**
     * 要提交的header
     *
     * @var array
     */
    public $header = [];


    /**
     * 新增一行报文头
     *
     * @param string $line
     */
    public function addHeader($line)
    {
        $this->header[] = $line;
    }


    /**
     * 清除所有的报文头
     */
    public function clearHeaders()
    {
        $this->header = [];
    }


    /**
     * 发送数据到指定url，返回一个结构
     *
     * @param array $input  要提交的数据
     *      url     (string)  url
     *      method  (string)  可选，默认为$this->method，详见类的$method属性
     *      query   (array)   可选，url中的查询串，默认为[]
     *      data    (array|string)   可选，post的数据，默认为[]
     *
     * @param array $curloptions  要额外设置的curl选项。如有设置，将用这个数组的选项覆盖默认选项
     * [
     *      选项 => 值,
     *      选项 => 值,
     *      选项 => 值,
     * ]
     *
     * @return array [$code, $msg, $data]
     */
    public function request(array $input, array $curloptions = [])
    {
        // url
        $url = $input["url"];

        // 请求方式method
        $method = (isset($input["method"])) ? $input["method"] : $this->method;
        $method = strtoupper($method);
        if (!in_array($method, $this->valid_methods)) {
            return [self::ERR_INVALID_METHOD, "无效的请求方式", null];
        }

        // 查询串
        $query = (isset($input["query"])) ? $input["query"] : '';
        if (is_array($query)) {
            $query = http_build_query($query);
        }

        // POST数据
        $data = (isset($input["data"])) ? $input["data"] : null;
        if (is_array($data)) {
            $data = http_build_query($data);
        }

        // CURL初始化
        $curl = curl_init();

        // 设置URL
        if ($query) {
            if (mb_strpos($url, "?") === false) {
                $url = $url . "?" . $query;
            } elseif (mb_substr($url, -1, 1) === "&") {
                $url = $url . $query;
            } else {
                $url = $url . "&" . $query;
            }
        }
        curl_setopt($curl, CURLOPT_URL, $url);

        // https
        if (mb_substr($url, 0, 8) === "https://") {
            curl_setopt($curl, CURLOPT_CAINFO, __DIR__ . '/cacert.pem');
        }

        // 请求的数据构造
        switch ($method) {
            case "POST":
                curl_setopt($curl, CURLOPT_POST, 1); // 设置提交方式为POST
                curl_setopt($curl, CURLOPT_POSTFIELDS, $data); // 要提交的POST字段
                break;
        }

        // 常规的curl设置
        $defaults = [
            CURLOPT_CONNECTTIMEOUT => 5, // 在尝试连接时等待的秒数，默认为5秒
            CURLOPT_TIMEOUT        => 30, // 设置curl最长的执行时间，防止死循环，默认为30秒
            CURLOPT_FOLLOWLOCATION => 1, // 使用自动跳转
            CURLOPT_AUTOREFERER    => 1, // 自动设置Referer
            CURLOPT_HEADER         => 0, // 不需要Header区域内容
            CURLOPT_RETURNTRANSFER => 1, // 获取的信息以文件流的形式返回
        ];

        // 设置
        curl_setopt_array($curl, $defaults);

        // 对报文头的特别处理
        $header = $this->header;
        if (array_key_exists(CURLOPT_HTTPHEADER, $curloptions)) {
            $header = array_merge($header, $curloptions[CURLOPT_HTTPHEADER]); // 合并
            $header = array_unique($header); // 去重
            unset($curloptions[CURLOPT_HTTPHEADER]);
        }
        if ($header) {
            curl_setopt_array($curl, [
                CURLOPT_HEADER     => 1,
                CURLOPT_HTTPHEADER => $header
            ]);
        }

        // 用参数要求的选项值代替默认值
        curl_setopt_array($curl, $curloptions);

        // 执行curl请求
        $data = curl_exec($curl);

        // 如果执行出错，返回错误信息
        $err_no = curl_errno($curl);
        if ($err_no) {
            return [$err_no, curl_error($curl), null];
        }

        // 关闭cURL请求
        curl_close($curl);

        // 如果正常，返回获得的数据
        return [0, null, $data];
    }


    /**
     * 向指定地址POST一个JSON字符串
     *
     * @param string $url
     * @param string $json
     */
    public function postjson($url, $json)
    {
        $input = [
            "url"    => $url,
            "method" => "POST",
            "data"   => $json,
        ];

        $curloptions = [
            CURLOPT_HEADER     => 1,
            CURLOPT_HTTPHEADER => [
                "Content-Type: application/json;charset=UTF-8"
            ],
        ];

        return $this->request($input, $curloptions);
    }


    /**
     * 解析http返回的数据流
     *
     * @param string $resp
     *
     * @return false|array  成功返回一个结构数组，失败返回false
     */
    public function parseHttpResponse($resp)
    {
        $matches = null;

        // 将数据流按行拆分
        $lines = explode("\r\n", $resp);

        // 第1行
        $line1 = $lines[0];
        $r = preg_match("/(HTTP\/)(\d\.\d)\s(\d\d\d)/", $line1, $matches);
        if ($r) {
            // 获取HTTP状态码
            $statusCode = $matches[3];
        } else {
            // 不合法，直接返回false，解析失败
            return false;
        }
        unset($lines[0]);

        // headers
        $headers = [];
        foreach ($lines as $n => $line) {
            if ($line === '') {
                unset($lines[$n]);
                break;
            }

            $headers[] = $line;
            unset($lines[$n]);
        }

        // body
        $body = implode("\r\n", $lines);

        // 输出
        return [
            "code"    => $statusCode,
            "headers" => $headers,
            "body"    => $body,
        ];
    }
}
