<?php

declare(strict_types=1);

namespace Core\Lib;

use chillerlan\QRCode\QRCode as ChillerlanQRCode;
use chillerlan\QRCode\QROptions;

/**
 * QRCode - SVG QR code generator.
 *
 * This is a thin adapter around the maintained `chillerlan/php-qrcode` package
 * (MIT, PHP 8.2+). The previous in-repo copy was a vendored port of
 * `splitbrain/php-qrcode` that had drifted out of maintenance and produced a
 * large number of static-analysis findings. Delegating to the maintained
 * upstream library keeps the public surface (`svg()`) stable for callers while
 * removing the legacy implementation.
 */
class QRCode
{
    protected mixed $data;
    /** @var array<string, mixed> */
    protected array $options;

    /**
     * @param array<string, mixed> $options
     */
    public function __construct(mixed $data, array $options = [])
    {
        $this->data = $data;
        $this->options = $options;
    }

    /**
     * Generate a standalone SVG string for the given data.
     *
     * @param mixed $data
     * @param array<string, mixed> $options
     */
    public static function svg(mixed $data, array $options = []): string
    {
        $scaleValue = $options['scale'] ?? 4;
        $scale = is_numeric($scaleValue) ? max(1, (int)$scaleValue) : 4;

        $quietzoneValue = $options['quietzone'] ?? 4;
        $quietzone = is_numeric($quietzoneValue) ? max(0, (int)$quietzoneValue) : 4;

        $qrOptions = new QROptions([
            'outputType'    => 'svg',
            'outputBase64'  => false,
            'scale'         => $scale,
            'addQuietzone'  => true,
            'quietzoneSize' => $quietzone,
        ]);

        $qr = new ChillerlanQRCode($qrOptions);
        $payload = is_string($data)
            ? $data
            : (is_scalar($data) ? (string)$data : ((string)json_encode($data, JSON_UNESCAPED_UNICODE)));
        $output = $qr->render($payload);
        return is_string($output) ? $output : '';
    }

    /**
     * Instance-based equivalent of {@see self::svg()}.
     */
    public function createSVG(): string
    {
        return self::svg($this->data, $this->options);
    }
}
