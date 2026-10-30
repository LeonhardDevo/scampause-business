<?php

declare(strict_types=1);

namespace Database\Seeders\Domain\Support;

use App\Domain\Support\Translit;
use Carbon\CarbonImmutable;

final class DemoDocuments
{
    /**
     * @param  list<string>  $lines
     */
    public static function pdf(string $title, array $lines): string
    {
        $text = ['BT', '/F1 16 Tf', '56 736 Td', '20 TL', '('.self::escape($title).') Tj', '/F1 10 Tf', '15 TL', 'T*'];

        foreach (array_slice($lines, 0, 44) as $line) {
            $text[] = '('.self::escape($line).') Tj T*';
        }

        $text[] = 'ET';
        $stream = implode("\n", $text);
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
            '<< /Length '.strlen($stream)." >>\nstream\n".$stream."\nendstream",
            '<< /Producer (ScamPause demo seed) /Title ('.self::escape($title).') >>',
        ];
        $pdf = "%PDF-1.4\n";
        $offsets = [];

        foreach ($objects as $index => $body) {
            $offsets[] = strlen($pdf);
            $pdf .= ($index + 1)." 0 obj\n".$body."\nendobj\n";
        }

        $xref = strlen($pdf);
        $pdf .= 'xref'."\n".'0 '.(count($objects) + 1)."\n".'0000000000 65535 f '."\n";

        foreach ($offsets as $offset) {
            $pdf .= str_pad((string) $offset, 10, '0', STR_PAD_LEFT).' 00000 n '."\n";
        }

        return $pdf.'trailer'."\n".'<< /Size '.(count($objects) + 1).' /Root 1 0 R /Info 6 0 R >>'."\n".'startxref'."\n".$xref."\n".'%%EOF'."\n";
    }

    /**
     * @param  array{from_name: string, from_email: string, to: string, subject: string, body: string, reply_to?: string|null, sent_at: CarbonImmutable, message_id: string, auth?: string|null}  $mail
     */
    public static function eml(array $mail): string
    {
        $headers = [
            'Return-Path: <'.$mail['from_email'].'>',
            'Authentication-Results: mx.demo.test; '.($mail['auth'] ?? 'spf=none; dkim=none; dmarc=none'),
            'From: '.self::encodeHeader($mail['from_name']).' <'.$mail['from_email'].'>',
            'To: '.$mail['to'],
            'Subject: '.self::encodeHeader($mail['subject']),
            'Date: '.$mail['sent_at']->format('D, d M Y H:i:s O'),
            'Message-ID: <'.$mail['message_id'].'>',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
        ];

        if (($mail['reply_to'] ?? null) !== null) {
            array_splice($headers, 3, 0, ['Reply-To: '.$mail['reply_to']]);
        }

        return implode("\r\n", $headers)."\r\n\r\n".str_replace("\n", "\r\n", $mail['body'])."\r\n";
    }

    private static function escape(string $value): string
    {
        $latin = preg_match('/[^\x20-\x7E]/', $value) === 1 ? Translit::toLatin($value) : $value;
        $ascii = (string) preg_replace('/[^\x20-\x7E]/', '?', $latin);

        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $ascii);
    }

    private static function encodeHeader(string $value): string
    {
        return preg_match('/[^\x20-\x7E]/', $value) === 1 ? '=?UTF-8?B?'.base64_encode($value).'?=' : $value;
    }
}
