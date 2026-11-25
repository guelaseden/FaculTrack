<?php
declare(strict_types=1);

/** One real browser session per actor, with HTTP cookies kept in memory by cURL. */
final class HttpClient
{
    private CurlHandle $curl;
    private string $token = '';
    public int $status = 0;
    public string $effectiveUrl = '';
    public string $body = '';

    public function __construct(private string $base)
    {
        $this->curl = curl_init();
        curl_setopt_array($this->curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 4,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_COOKIEFILE => '',
        ]);
    }

    public function get(string $page, array $query = []): string
    {
        return $this->request($page, $query);
    }

    public function post(string $page, array $fields = [], array $query = []): string
    {
        return $this->request($page, $query, $fields + ['csrf' => $this->token]);
    }

    public function login(string $employeeId, string $password, bool $remember = false): string
    {
        $this->get('login');
        $fields=['employee_id'=>$employeeId,'password'=>$password];
        if ($remember) $fields['remember']='1';
        return $this->post('login', $fields);
    }

    private function request(string $page, array $query, ?array $fields = null): string
    {
        curl_setopt($this->curl, CURLOPT_URL, $this->base . '?' . http_build_query(['p' => $page] + $query));
        if ($fields === null) {
            curl_setopt($this->curl, CURLOPT_HTTPGET, true);
        } else {
            curl_setopt($this->curl, CURLOPT_POST, true);
            curl_setopt($this->curl, CURLOPT_POSTFIELDS, $fields);
        }
        $body = curl_exec($this->curl);
        if ($body === false) {
            throw new RuntimeException(curl_error($this->curl));
        }
        $this->status = curl_getinfo($this->curl, CURLINFO_RESPONSE_CODE);
        $this->effectiveUrl = curl_getinfo($this->curl, CURLINFO_EFFECTIVE_URL);
        $this->body = $body;
        if (preg_match('~<input\b[^>]*\bname=["\']csrf["\'][^>]*\bvalue=["\']([^"\']+)~i', $body, $match)) {
            $this->token = html_entity_decode($match[1], ENT_QUOTES);
        }
        if ($this->status >= 500) {
            throw new RuntimeException("Unexpected HTTP {$this->status} on $page");
        }
        return $body;
    }
}
