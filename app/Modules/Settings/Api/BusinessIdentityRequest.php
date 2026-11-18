<?php
declare(strict_types=1);

final class BusinessIdentityRequest
{
    /** @return array{body: array, csrf_required: bool, csrf_token: string} */
    public static function post(string $rawBody, array $post, array $server, bool $isJwt): array
    {
        $decoded=json_decode($rawBody,true);
        $body=is_array($decoded)?$decoded:$post;
        $token=$body['csrf_token']??$post['csrf_token']??$server['HTTP_X_CSRF_TOKEN']??'';
        return ['body'=>$body,'csrf_required'=>!$isJwt,'csrf_token'=>is_string($token)?$token:''];
    }
}
