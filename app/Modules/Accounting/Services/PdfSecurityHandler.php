<?php
declare(strict_types=1);

final class PdfSecurityHandler
{
    private const PASSWORD_PADDING = "\x28\xBF\x4E\x5E\x4E\x75\x8A\x41\x64\x00\x4E\x56\xFF\xFA\x01\x08"
        . "\x2E\x2E\x00\xB6\xD0\x68\x3E\x80\x2F\x0C\xA9\xFE\x64\x53\x69\x7A";

    public static function isEncrypted(string $pdfBytes): bool
    {
        if (preg_match_all('/trailer\s*(<<.*?>>)/sU', $pdfBytes, $matches)) {
            foreach ($matches[1] as $dictionary) {
                if (preg_match('/\/Encrypt\s+\d+\s+\d+\s+R\b/', $dictionary)) {
                    return true;
                }
            }
        }

        return (bool)preg_match(
            '/\d+\s+\d+\s+obj\b(?:(?!endobj).)*\/Type\s*\/XRef\b(?:(?!endobj).)*\/Encrypt\s+\d+\s+\d+\s+R\b/s',
            $pdfBytes
        );
    }

    public static function decrypt(string $pdfBytes): string
    {
        if (strncmp($pdfBytes, '%PDF-', 5) !== 0) {
            throw new RuntimeException('Malformed PDF: missing PDF header.');
        }
        if (!self::isEncrypted($pdfBytes)) {
            throw new RuntimeException('Malformed PDF: encryption trailer was not found.');
        }
        if (preg_match('/\/Type\s*\/XRef\b/', $pdfBytes)) {
            throw new RuntimeException('Unsupported PDF: xref streams are not supported.');
        }

        $trailer = self::lastTrailer($pdfBytes);
        if (!preg_match('/\/Encrypt\s+(\d+)\s+(\d+)\s+R\b/', $trailer, $encryptRef)) {
            throw new RuntimeException('Malformed PDF: encryption reference was not found.');
        }
        $objects = self::readObjects($pdfBytes);
        $encryptKey = (int)$encryptRef[1] . ':' . (int)$encryptRef[2];
        if (!isset($objects[$encryptKey])) {
            throw new RuntimeException('Malformed PDF: encryption dictionary was not found.');
        }

        $encryption = self::parseDictionary($objects[$encryptKey]['body']);
        $settings = self::encryptionSettings($encryption, $trailer);
        self::verifyEmptyPassword($settings);

        unset($objects[$encryptKey]);
        foreach ($objects as &$object) {
            $object['body'] = self::decryptObject(
                $object['body'],
                $object['number'],
                $object['generation'],
                $settings
            );
        }
        unset($object);

        return self::rebuild($pdfBytes, $objects, $trailer);
    }

    private static function lastTrailer(string $pdfBytes): string
    {
        if (!preg_match_all('/trailer\s*(<<.*?>>)/sU', $pdfBytes, $matches) || !$matches[1]) {
            throw new RuntimeException('Malformed PDF: trailer was not found.');
        }
        return (string)end($matches[1]);
    }

    private static function readObjects(string $pdfBytes): array
    {
        if (!preg_match_all('/(?:^|[\r\n])(\d+)\s+(\d+)\s+obj\b(.*?)endobj/s', $pdfBytes, $matches, PREG_SET_ORDER)) {
            throw new RuntimeException('Malformed PDF: no indirect objects were found.');
        }
        $objects = [];
        foreach ($matches as $match) {
            $number = (int)$match[1];
            $generation = (int)$match[2];
            $objects[$number . ':' . $generation] = [
                'number' => $number,
                'generation' => $generation,
                'body' => trim($match[3], "\r\n"),
            ];
        }
        return $objects;
    }

    private static function encryptionSettings(array $dictionary, string $trailer): array
    {
        if (($dictionary['Filter'] ?? null) !== '/Standard') {
            throw new RuntimeException('Unsupported PDF security handler.');
        }
        $version = (int)($dictionary['V'] ?? 0);
        $revision = (int)($dictionary['R'] ?? 0);
        if (!(($version === 1 && $revision === 2)
            || ($version === 2 && $revision === 3)
            || ($version === 4 && $revision === 4))) {
            throw new RuntimeException('Unsupported PDF security handler version.');
        }
        foreach (['O', 'U', 'P'] as $required) {
            if (!array_key_exists($required, $dictionary)) {
                throw new RuntimeException('Malformed PDF encryption dictionary.');
            }
        }
        if (!preg_match('/\/ID\s*\[\s*(<[^>]*>|\((?:\\.|[^\\)])*\))/s', $trailer, $idMatch)) {
            throw new RuntimeException('Malformed PDF: file identifier was not found.');
        }
        $id = self::decodePdfString($idMatch[1]);
        $length = $revision === 2 ? 5 : (int)(($dictionary['Length'] ?? 128) / 8);
        if ($length < 5 || $length > 16) {
            throw new RuntimeException('Unsupported PDF encryption key length.');
        }
        $encryptMetadata = ($dictionary['EncryptMetadata'] ?? true) !== false;
        $material = self::PASSWORD_PADDING . $dictionary['O'] . pack('V', ((int)$dictionary['P']) & 0xffffffff) . $id;
        if ($revision >= 4 && !$encryptMetadata) {
            $material .= "\xFF\xFF\xFF\xFF";
        }
        $digest = md5($material, true);
        if ($revision >= 3) {
            for ($i = 0; $i < 50; $i++) {
                $digest = md5(substr($digest, 0, $length), true);
            }
        }
        $fileKey = substr($digest, 0, $length);

        $streamMethod = 'rc4';
        $stringMethod = 'rc4';
        if ($version === 4) {
            $streamMethod = self::cryptFilterMethod($dictionary, (string)($dictionary['StmF'] ?? '/Identity'));
            $stringMethod = self::cryptFilterMethod($dictionary, (string)($dictionary['StrF'] ?? '/Identity'));
        }
        return [
            'revision' => $revision,
            'fileKey' => $fileKey,
            'id' => $id,
            'u' => $dictionary['U'],
            'streamMethod' => $streamMethod,
            'stringMethod' => $stringMethod,
            'encryptMetadata' => $encryptMetadata,
        ];
    }

    private static function cryptFilterMethod(array $dictionary, string $filterName): string
    {
        if ($filterName === '/Identity') {
            return 'identity';
        }
        $name = ltrim($filterName, '/');
        $filters = $dictionary['CF'] ?? null;
        if (!is_array($filters) || !isset($filters[$name]) || !is_array($filters[$name])) {
            throw new RuntimeException('Unsupported PDF crypt filter.');
        }
        $method = $filters[$name]['CFM'] ?? null;
        if ($method === '/V2') {
            return 'rc4';
        }
        if ($method === '/AESV2') {
            return 'aes';
        }
        if ($method === '/None') {
            return 'identity';
        }
        throw new RuntimeException('Unsupported PDF crypt filter method.');
    }

    private static function verifyEmptyPassword(array $settings): void
    {
        if ($settings['revision'] === 2) {
            $expected = self::rc4(self::PASSWORD_PADDING, $settings['fileKey']);
            $valid = hash_equals($expected, substr($settings['u'], 0, 32));
        } else {
            $digest = md5(self::PASSWORD_PADDING . $settings['id'], true);
            $value = self::rc4($digest, $settings['fileKey']);
            for ($i = 1; $i <= 19; $i++) {
                $value = self::rc4($value, self::xorKey($settings['fileKey'], $i));
            }
            $valid = hash_equals(substr($value, 0, 16), substr($settings['u'], 0, 16));
        }
        if (!$valid) {
            throw new RuntimeException('This PDF requires a user password; the empty password did not open it.');
        }
    }

    private static function decryptObject(string $body, int $number, int $generation, array $settings): string
    {
        $streamStart = null;
        $streamEnd = null;
        // The keyword follows the dictionary's closing ">>" with or without a line break (">>stream" is common).
        if (preg_match('/>>\s*stream(?:\r\n|\n|\r)/', $body, $streamMatch, PREG_OFFSET_CAPTURE)) {
            $marker = $streamMatch[0][0];
            $markerOffset = $streamMatch[0][1] + 2;
            $streamStart = $streamMatch[0][1] + strlen($marker);
            $dictionaryPart = substr($body, 0, $markerOffset);
            if (preg_match('/\/Length\s+(\d+)\b(?!\s+\d+\s+R\b)/', $dictionaryPart, $lengthMatch)) {
                $streamEnd = $streamStart + (int)$lengthMatch[1];
                if ($streamEnd > strlen($body) || strpos(substr($body, $streamEnd), 'endstream') === false) {
                    throw new RuntimeException('Malformed PDF stream length.');
                }
            } else {
                // An indirect /Length (N G R): the data ends at the line break before the last "endstream".
                $endKeyword = strrpos($body, 'endstream');
                if ($endKeyword === false || $endKeyword < $streamStart) {
                    throw new RuntimeException('Malformed PDF stream length.');
                }
                $streamEnd = $endKeyword;
                if (substr($body, $streamEnd - 2, 2) === "\r\n") {
                    $streamEnd -= 2;
                } elseif ($streamEnd > $streamStart && in_array($body[$streamEnd - 1], ["\n", "\r"], true)) {
                    $streamEnd -= 1;
                }
            }
        }

        $prefix = $streamStart === null ? $body : substr($body, 0, $streamStart);
        $prefix = self::decryptStrings($prefix, $number, $generation, $settings['fileKey'], $settings['stringMethod']);
        if ($streamStart === null) {
            return $prefix;
        }

        $stream = substr($body, $streamStart, $streamEnd - $streamStart);
        $suffix = substr($body, $streamEnd);
        $isXref = (bool)preg_match('/\/Type\s*\/XRef\b/', $prefix);
        $isMetadata = (bool)preg_match('/\/Type\s*\/Metadata\b/', $prefix);
        $method = ($isXref || ($isMetadata && !$settings['encryptMetadata']))
            ? 'identity'
            : $settings['streamMethod'];
        $stream = self::decryptValue($stream, $number, $generation, $settings['fileKey'], $method);
        // Also turns an indirect "/Length N G R" into the direct length of the rewritten stream.
        $prefix = preg_replace('/\/Length\s+\d+(?:\s+\d+\s+R\b)?/', '/Length ' . strlen($stream), $prefix, 1);
        return $prefix . $stream . $suffix;
    }

    private static function decryptStrings(string $source, int $number, int $generation, string $fileKey, string $method): string
    {
        $out = '';
        $length = strlen($source);
        for ($i = 0; $i < $length;) {
            if (substr($source, $i, 2) === '<<') {
                $out .= '<<';
                $i += 2;
                continue;
            }
            if ($source[$i] === '%') {
                $end = strpos($source, "\n", $i);
                if ($end === false) $end = $length;
                $out .= substr($source, $i, $end - $i);
                $i = $end;
                continue;
            }
            if ($source[$i] === '(') {
                [$raw, $next] = self::readLiteralString($source, $i);
                $plain = self::decryptValue($raw, $number, $generation, $fileKey, $method);
                $out .= '<' . strtoupper(bin2hex($plain)) . '>';
                $i = $next;
                continue;
            }
            if ($source[$i] === '<' && ($i + 1 >= $length || $source[$i + 1] !== '<')) {
                $end = strpos($source, '>', $i + 1);
                if ($end === false) throw new RuntimeException('Malformed PDF hex string.');
                $plain = self::decryptValue(self::decodePdfString(substr($source, $i, $end - $i + 1)), $number, $generation, $fileKey, $method);
                $out .= '<' . strtoupper(bin2hex($plain)) . '>';
                $i = $end + 1;
                continue;
            }
            $out .= $source[$i++];
        }
        return $out;
    }

    private static function decryptValue(string $value, int $number, int $generation, string $fileKey, string $method): string
    {
        if ($method === 'identity') return $value;
        $salt = $method === 'aes' ? 'sAlT' : '';
        $objectKey = substr(md5($fileKey
            . substr(pack('V', $number), 0, 3)
            . substr(pack('V', $generation), 0, 2)
            . $salt, true), 0, min(strlen($fileKey) + 5, 16));
        if ($method === 'rc4') return self::rc4($value, $objectKey);
        if (strlen($value) < 32 || strlen($value) % 16 !== 0 || !function_exists('openssl_decrypt')) {
            throw new RuntimeException('Malformed or unsupported AES-encrypted PDF value.');
        }
        $iv = substr($value, 0, 16);
        $plain = openssl_decrypt(substr($value, 16), 'aes-128-cbc', $objectKey, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $iv);
        if ($plain === false || $plain === '') throw new RuntimeException('Could not decrypt an AES-encrypted PDF value.');
        $padding = ord(substr($plain, -1));
        if ($padding < 1 || $padding > 16 || substr($plain, -$padding) !== str_repeat(chr($padding), $padding)) {
            throw new RuntimeException('Malformed AES padding in PDF.');
        }
        return substr($plain, 0, -$padding);
    }

    private static function rebuild(string $original, array $objects, string $trailer): string
    {
        usort($objects, static function (array $a, array $b): int {
            return $a['number'] <=> $b['number'] ?: $a['generation'] <=> $b['generation'];
        });
        preg_match('/^%PDF-[^\r\n]+/', $original, $header);
        $pdf = ($header[0] ?? '%PDF-1.7') . "\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        $max = 0;
        foreach ($objects as $object) {
            $max = max($max, $object['number']);
            $offsets[$object['number']] = [strlen($pdf), $object['generation']];
            $pdf .= $object['number'] . ' ' . $object['generation'] . " obj\n" . $object['body'] . "\nendobj\n";
        }
        $xrefOffset = strlen($pdf);
        $pdf .= "xref\n0 " . ($max + 1) . "\n0000000000 65535 f \n";
        for ($i = 1; $i <= $max; $i++) {
            if (isset($offsets[$i])) {
                $pdf .= sprintf('%010d %05d n ', $offsets[$i][0], $offsets[$i][1]) . "\n";
            } else {
                $pdf .= "0000000000 00000 f \n";
            }
        }
        $trailer = preg_replace('/\/Encrypt\s+\d+\s+\d+\s+R\b/', '', $trailer);
        $trailer = preg_replace('/\/(?:Prev|XRefStm)\s+\d+\b/', '', $trailer);
        if (preg_match('/\/Size\s+\d+\b/', $trailer)) {
            $trailer = preg_replace('/\/Size\s+\d+\b/', '/Size ' . ($max + 1), $trailer, 1);
        } else {
            $trailer = preg_replace('/<<\s*/', '<< /Size ' . ($max + 1) . ' ', $trailer, 1);
        }
        return $pdf . "trailer\n" . $trailer . "\nstartxref\n" . $xrefOffset . "\n%%EOF\n";
    }

    private static function parseDictionary(string $source): array
    {
        $start = strpos($source, '<<');
        if ($start === false) throw new RuntimeException('Malformed PDF dictionary.');
        $position = $start;
        $value = self::parseValue($source, $position);
        if (!is_array($value)) throw new RuntimeException('Malformed PDF dictionary.');
        return $value;
    }

    private static function parseValue(string $source, int &$position)
    {
        self::skipWhitespace($source, $position);
        if (substr($source, $position, 2) === '<<') {
            $position += 2;
            $dictionary = [];
            while (true) {
                self::skipWhitespace($source, $position);
                if (substr($source, $position, 2) === '>>') {
                    $position += 2;
                    return $dictionary;
                }
                $key = self::readName($source, $position);
                $dictionary[$key] = self::parseValue($source, $position);
            }
        }
        if (($source[$position] ?? '') === '[') {
            $position++;
            $items = [];
            while (true) {
                self::skipWhitespace($source, $position);
                if (($source[$position] ?? '') === ']') { $position++; return $items; }
                $items[] = self::parseValue($source, $position);
            }
        }
        if (($source[$position] ?? '') === '/') return '/' . self::readName($source, $position);
        if (($source[$position] ?? '') === '(') {
            [$value, $position] = self::readLiteralString($source, $position);
            return $value;
        }
        if (($source[$position] ?? '') === '<') {
            $end = strpos($source, '>', $position + 1);
            if ($end === false) throw new RuntimeException('Malformed PDF hex string.');
            $value = self::decodePdfString(substr($source, $position, $end - $position + 1));
            $position = $end + 1;
            return $value;
        }
        if (preg_match('/\G(true|false|null|-?\d+(?:\.\d+)?)/', $source, $match, 0, $position)) {
            $position += strlen($match[0]);
            if ($match[1] === 'true') return true;
            if ($match[1] === 'false') return false;
            if ($match[1] === 'null') return null;
            return strpos($match[1], '.') === false ? (int)$match[1] : (float)$match[1];
        }
        throw new RuntimeException('Malformed PDF dictionary value.');
    }

    private static function readName(string $source, int &$position): string
    {
        if (($source[$position] ?? '') !== '/') throw new RuntimeException('Malformed PDF dictionary key.');
        $position++;
        if (!preg_match('/\G([^\x00\x09\x0A\x0C\x0D\x20\(\)<>\[\]\{\}\/%%]+)/', $source, $match, 0, $position)) {
            throw new RuntimeException('Malformed PDF name.');
        }
        $position += strlen($match[1]);
        return preg_replace_callback('/#([0-9A-Fa-f]{2})/', static fn(array $m): string => chr(hexdec($m[1])), $match[1]);
    }

    private static function skipWhitespace(string $source, int &$position): void
    {
        $length = strlen($source);
        while ($position < $length) {
            if (strpos("\x00\x09\x0A\x0C\x0D\x20", $source[$position]) !== false) { $position++; continue; }
            if ($source[$position] === '%') {
                while ($position < $length && $source[$position] !== "\n" && $source[$position] !== "\r") $position++;
                continue;
            }
            break;
        }
    }

    private static function readLiteralString(string $source, int $position): array
    {
        $position++;
        $depth = 1;
        $value = '';
        $length = strlen($source);
        while ($position < $length && $depth > 0) {
            $char = $source[$position++];
            if ($char === '\\') {
                if ($position >= $length) break;
                $escaped = $source[$position++];
                $map = ['n' => "\n", 'r' => "\r", 't' => "\t", 'b' => "\x08", 'f' => "\x0C"];
                if (isset($map[$escaped])) { $value .= $map[$escaped]; continue; }
                if ($escaped === "\r" || $escaped === "\n") {
                    if ($escaped === "\r" && ($source[$position] ?? '') === "\n") $position++;
                    continue;
                }
                if ($escaped >= '0' && $escaped <= '7') {
                    $octal = $escaped;
                    for ($j = 0; $j < 2 && ($source[$position] ?? '') >= '0' && ($source[$position] ?? '') <= '7'; $j++) $octal .= $source[$position++];
                    $value .= chr(octdec($octal));
                    continue;
                }
                $value .= $escaped;
            } elseif ($char === '(') {
                $depth++;
                $value .= $char;
            } elseif ($char === ')') {
                $depth--;
                if ($depth > 0) $value .= $char;
            } else {
                $value .= $char;
            }
        }
        if ($depth !== 0) throw new RuntimeException('Malformed PDF literal string.');
        return [$value, $position];
    }

    private static function decodePdfString(string $token): string
    {
        if ($token[0] === '(') return self::readLiteralString($token, 0)[0];
        $hex = preg_replace('/\s+/', '', substr($token, 1, -1));
        if (strlen($hex) % 2) $hex .= '0';
        $value = hex2bin($hex);
        if ($value === false) throw new RuntimeException('Malformed PDF hex string.');
        return $value;
    }

    private static function xorKey(string $key, int $value): string
    {
        $out = '';
        for ($i = 0, $length = strlen($key); $i < $length; $i++) $out .= chr(ord($key[$i]) ^ $value);
        return $out;
    }

    private static function rc4(string $data, string $key): string
    {
        $state = range(0, 255);
        $j = 0;
        $keyLength = strlen($key);
        for ($i = 0; $i < 256; $i++) {
            $j = ($j + $state[$i] + ord($key[$i % $keyLength])) & 255;
            [$state[$i], $state[$j]] = [$state[$j], $state[$i]];
        }
        $out = '';
        $i = $j = 0;
        for ($n = 0, $length = strlen($data); $n < $length; $n++) {
            $i = ($i + 1) & 255;
            $j = ($j + $state[$i]) & 255;
            [$state[$i], $state[$j]] = [$state[$j], $state[$i]];
            $out .= chr(ord($data[$n]) ^ $state[($state[$i] + $state[$j]) & 255]);
        }
        return $out;
    }
}
