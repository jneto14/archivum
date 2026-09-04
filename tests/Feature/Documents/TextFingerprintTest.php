<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use App\Services\Ocr\TextFingerprint;
use Tests\TestCase;

/**
 * Covers the fingerprint itself: that it survives the differences between two
 * scans of one page, and that it still separates two documents that only look
 * alike.
 *
 * Both halves matter equally, and the second is the harder one. A fingerprint
 * too strict to match a rescan warns nobody about anything; one loose enough to
 * match next month's invoice from the same supplier fires on every invoice and
 * teaches people to dismiss it without reading.
 */
class TextFingerprintTest extends TestCase
{
    /** @var string An invoice's worth of text — long enough to fingerprint, which a one-line fixture is not. */
    private const INVOICE = <<<'TEXT'
        Fatura FT2026/1240 emitida em 20/08/2026 pela Exemplo Lda, com sede na Rua das Oliveiras
        numero 14, Lisboa. Contribuinte 501442600. Servico de manutencao anual da instalacao
        eletrica, incluindo substituicao do quadro e verificacao das ligacoes de terra.
        Total a pagar 1.250,50 EUR, com vencimento a trinta dias da data de emissao.
        Pagamento por transferencia bancaria para o IBAN indicado no rodape deste documento.
        TEXT;

    public function test_case_accents_and_punctuation_are_not_part_of_the_text()
    {
        $fingerprints = app(TextFingerprint::class);

        $this->assertSame(
            $fingerprints->normalize('Manutenção Elétrica, Lda.'),
            $fingerprints->normalize('manutencao eletrica lda'),
            'Exactly the differences OCR invents between two passes must normalise away.',
        );
    }

    public function test_the_same_text_fingerprints_identically()
    {
        $fingerprints = app(TextFingerprint::class);

        $this->assertSame(0, $this->distanceBetween(self::INVOICE, self::INVOICE));
        $this->assertNotNull($fingerprints->simhash(self::INVOICE));
    }

    public function test_a_rescan_of_the_same_page_stays_within_the_threshold()
    {
        // The same page photographed again: a handful of characters
        // misrecognised, which is what a second pass over one invoice produces.
        $rescanned = str_replace(
            ['Oliveiras', 'quadro', 'trinta', 'Lisboa', 'bancaria'],
            ['Ollveiras', 'quaclro', 'trlnta', 'Llsboa', 'banoaria'],
            self::INVOICE,
        );

        $distance = $this->distanceBetween(self::INVOICE, $rescanned);

        $this->assertLessThanOrEqual(
            $this->threshold(),
            $distance,
            "A rescan of the same page landed {$distance} bits away, so it would never be reported as a duplicate.",
        );
    }

    public function test_next_months_invoice_from_the_same_supplier_is_not_a_duplicate()
    {
        // The same template, the same supplier, the same wording — only the
        // number, the date and the total differ. Without weighting the shingles
        // that carry numbers, this pair sits as close as a rescan does and the
        // warning fires on every invoice anybody files.
        $next = str_replace(
            ['FT2026/1240', '20/08/2026', '1.250,50', 'manutencao anual da instalacao'],
            ['FT2026/1998', '04/11/2026', '389,90', 'reparacao pontual da instalacao'],
            self::INVOICE,
        );

        $distance = $this->distanceBetween(self::INVOICE, $next);

        $this->assertGreaterThan(
            $this->threshold(),
            $distance,
            "Two different invoices on one template landed {$distance} bits apart, close enough to be reported as copies.",
        );
    }

    public function test_a_different_document_is_nowhere_near()
    {
        $other = <<<'TEXT'
            Apolice de seguro automovel numero 88213394 celebrada com a Seguradora Exemplo SA
            para o veiculo com a matricula 12-AB-34, marca Renault, modelo Clio, do ano de 2019.
            Cobertura de responsabilidade civil obrigatoria, protecao juridica e assistencia em
            viagem. Premio anual de 340,00 EUR, pago em duas prestacoes semestrais, com inicio
            de vigencia a 01/03/2026 e renovacao automatica salvo denuncia com trinta dias.
            TEXT;

        $this->assertGreaterThan($this->threshold(), $this->distanceBetween(self::INVOICE, $other));
    }

    public function test_too_little_text_is_not_fingerprinted_at_all()
    {
        $this->assertNull(
            app(TextFingerprint::class)->simhash('Recibo 12'),
            'A fingerprint of a few words carries no signal, and would match every other near-empty scan.',
        );
    }

    /**
     * Fingerprint two texts and measure how far apart they land.
     *
     * @param string $first One text.
     * @param string $second The other.
     *
     * @return int The distance in bits.
     */
    private function distanceBetween(string $first, string $second): int
    {
        $fingerprints = app(TextFingerprint::class);

        return $fingerprints->distance(
            (int) $fingerprints->simhash($first),
            (int) $fingerprints->simhash($second),
        );
    }

    /**
     * @return int The configured distance at which two attachments stop counting as the same document.
     */
    private function threshold(): int
    {
        return (int) config('archivum.intake.duplicate_max_distance');
    }
}
