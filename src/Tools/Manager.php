<?php

namespace Endropie\LumenAccurateClient\Tools;

use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;

class Manager extends Facade
{
    protected $api;

    protected $conf = [
        "authorize_uri" => "https://account.accurate.id/oauth/authorize",
        "oauth_token_uri" => "https://account.accurate.id/oauth/token",
        "dbopen_uri" => "https://account.accurate.id/api/open-db.do",
    ];

    public function getConfig ($var)
    {
        return $this->conf[$var];
    }

    public function url ($uri = "")
    {
        return (string) (config('accurate.session.db.host') . $uri);
    }

    public function client ()
    {
        $token = config('accurate.session.auth.access_token');
        $session = config('accurate.session.db.session');

        if ($token && $session)  {

            $headers = ['X-session-ID' => $session];

            $client = app('http-accurate')->withToken($token)->withHeaders($headers);

            return $client;
        }

        else return abort(406, '[ACCURATE] Unauthorized.');
    }

    public function setDatabase ()
    {
        $id = config('accurate.database.id');
        $token = config('accurate.session.auth.access_token');
        $response =  app('http-accurate')->withToken($token)->get($this->getConfig('dbopen_uri'), ['id' => $id])->throw();
        if($response->successful() && $data = $response->json())
        {
            config()->set('accurate.session.db.id', $id);
            config()->set('accurate.session.db.host', $data['host'] ?? null);
            config()->set('accurate.session.db.admin', $data['admin'] ?? null);
            config()->set('accurate.session.db.session', $data['session'] ?? null);
        }
        return $response->json();
    }

    public function callbackUrl ()
    {
        return request()->getSchemeAndHttpHost()
            . (string) stringable(request('redirect_uri', config('accurate.route.callback', '/accurate/callback')))->start('/')
            . (string) (request()->has('redirect_app') ? "?redirect_app=". request('redirect_app') : "");
    }

    public function beforeLogin()
    {
        if (!env('ACCURATE_APPID')) abort(504, "[ACCURATE] ACCURATE_APPID environment undefined!");
        if (!env('ACCURATE_SECRET')) abort(504, "[ACCURATE] ACCURATE_SECRET environment undefined!");

        if (!config('accurate.database.id')) abort(504, "[ACCURATE] DATABASE ID undefined!");

        return true;
    }

    public function getParseDataCallback()
    {
        return encrypt(json_encode([
            'auth' => (array) collect(config('accurate.session.auth'))->only(['access_token', 'refresh_token', 'expires_in'])->toArray(),
            'db' => (array) config('accurate.session.db'),
            'unique' => uniqid(rand(), CRYPT_EXT_DES)
        ]));
    }

    public function on ($module, $action='list', $values=[], $method=null)
    {
        $url = $this->url(config("accurate.modules.$module.$action"));

        $exe = $method
          ? $this->client()->{$method}($url, $values)
          : $this->client()->asForm()->get($url, $values);

        return $exe->throw();
    }

    public static function routes ()
    {
        app('router')->get(config('accurate.route.callback', '/accurate/callback'), function () {
            app('accurate')->beforeLogin();

            $appid = env('ACCURATE_APPID');
            $secret = env('ACCURATE_SECRET');

            $response =  app('http-accurate')->asForm()
                ->withBasicAuth($appid, $secret)
                ->post(app('accurate')->getConfig('oauth_token_uri'), [
                'code' => request('code'),
                'grant_type' =>	'authorization_code',
                'redirect_uri' => app('accurate')->callbackUrl(),
            ])->throw();

            if ($response->successful()) {
                $auth = $response->json();
                config()->set('accurate.session.auth', $auth);
                $openDB =  app('accurate')->setDatabase(config('accurate.database.id')) ;

                if($redirected = request('redirect_app'))
                {
                    $token = app('accurate')->getParseDataCallback();

                    $sdata = http_build_query(['X-Accurate' => $token]);
                    $sdata = (strpos($sdata, '?') === false)
                        ? stringable($sdata)->start('?') : stringable($sdata)->start('&');
                    $redirected .= $sdata;

                    return redirect($redirected);
                }

                return response()->json(['X-Accurate' => app('accurate')->getParseDataCallback()]);
            }
            else return $response->json();

        });

        app('router')->get(config('accurate.route.login', '/accurate/login'), function () {

            app('accurate')->beforeLogin();

            $parameter = http_build_query([
                'client_id' => env('ACCURATE_APPID'),
                'response_type' => 'code',
                'redirect_uri' => app('accurate')->callbackUrl(),
                'scope' => implode(' ', config('accurate.scope', [])),
            ]);

            $uri = app('accurate')->getConfig('authorize_uri') . "?$parameter";

            return redirect($uri);
        });
    }

}
