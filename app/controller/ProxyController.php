<?php
namespace app\controller;

use app\BaseController;
use think\facade\Session;

class ProxyController extends BaseController
{
    public function index()
    {
        if (!Session::has('user_id')) {
            return redirect('/user/login');
        }
        return view('proxy/index');
    }

    public function proxy($site)
    {
        if (!Session::has('user_id')) {
            return redirect('/user/login');
        }

        $allowedSites = ['twitter', 'facebook', 'youtube'];
        if (!in_array($site, $allowedSites)) {
            return '不支持的网站';
        }

        $url = $this->request->get('url');
        if (!$url) {
            $url = $this->getDefaultUrl($site);
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36');
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);

        curl_close($ch);

        // 修改响应中的链接
        $response = $this->rewriteUrls($response, $site);

        header('Content-Type: ' . $contentType);
        echo $response;
    }

    private function getDefaultUrl($site)
    {
        $urls = [
            'twitter' => 'https://twitter.com',
            'facebook' => 'https://www.facebook.com',
            'youtube' => 'https://www.youtube.com'
        ];
        return $urls[$site] ?? '';
    }

    private function rewriteUrls($html, $site)
    {
        // 简单重写URL为代理路径
        $patterns = [
            '/https?:\/\/(www\.)?twitter\.com/' => '/proxy/twitter?url=https://twitter.com',
            '/https?:\/\/(www\.)?facebook\.com/' => '/proxy/facebook?url=https://facebook.com',
            '/https?:\/\/(www\.)?youtube\.com/' => '/proxy/youtube?url=https://youtube.com'
        ];

        foreach ($patterns as $pattern => $replacement) {
            $html = preg_replace($pattern, $replacement, $html);
        }

        return $html;
    }
}