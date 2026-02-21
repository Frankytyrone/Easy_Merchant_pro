<?php
// ebmpro_api/email.php — send invoice emails with tracking pixel.

require_once __DIR__ . '/config.php';

/**
 * Build the HTML email body for an invoice.
 *
 * @param array  $invoice  Invoice data array.
 * @param string $token    Unique tracking token for this send.
 * @return string          HTML email body.
 */
function buildEmailBody(array $invoice, string $token): string {
    $html  = '<!DOCTYPE html><html><body>';
    $html .= '<p>Please find your invoice attached.</p>';
    $html .= '<img src="' . TRACK_URL . '?t=' . urlencode($token) . '" width="1" height="1" alt="" style="display:none;">';
    $html .= '</body></html>';

    return $html;
}
