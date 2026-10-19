<?php

namespace App\Domain\Integration;

use App\Integrations\Sap\Dto\Quantity;
use App\Integrations\Sap\Dto\SapDocument;

/**
 * Compares a document read from SAP with the lines that were requested.
 * A document is accepted only if it has exactly the requested lines
 * (item, lot, warehouse, quantity), in any order.
 */
final class DocumentMatch
{
    /**
     * @param  list<array{item_code: string, lot_code: string, quantity: string, warehouse: string}>  $expected
     * @return list<string> differences; empty when the document matches
     */
    public static function differences(SapDocument $document, array $expected): array
    {
        $key = fn (string $item, string $lot, string $warehouse): string => "{$item}|{$lot}|{$warehouse}";
        $wanted = [];
        foreach ($expected as $line) {
            $wanted[$key($line['item_code'], $line['lot_code'], $line['warehouse'])] = new Quantity($line['quantity']);
        }

        $found = [];
        $differences = [];
        foreach ($document->lines as $line) {
            $k = $key($line->itemCode, $line->lotCode, $line->warehouse);

            if (isset($found[$k])) {
                $differences[] = "Línea repetida en SAP: {$k}";
            }
            $found[$k] = true;

            if (! isset($wanted[$k])) {
                $differences[] = "Línea que no se pidió: {$k}";
            } elseif (! $wanted[$k]->equals($line->quantity)) {
                $differences[] = "Cantidad distinta en {$k}: pedida {$wanted[$k]->value}, en SAP {$line->quantity->value}";
            }
        }

        foreach (array_keys($wanted) as $k) {
            if (! isset($found[$k])) {
                $differences[] = "Falta la línea {$k}";
            }
        }

        return $differences;
    }

    /**
     * SAP line number of each requested item + lot (+ warehouse).
     *
     * @return array<string, int>
     */
    public static function lineNumbers(SapDocument $document): array
    {
        $numbers = [];
        foreach ($document->lines as $line) {
            $numbers["{$line->itemCode}|{$line->lotCode}|{$line->warehouse}"] = $line->lineNum;
        }

        return $numbers;
    }
}
