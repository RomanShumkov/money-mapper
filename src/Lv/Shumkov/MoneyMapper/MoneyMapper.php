<?php
namespace Lv\Shumkov\MoneyMapper;

use Money\Money;
use Money\Currency;

use InvalidArgumentException;

/**
 * Contains ORM logic for Money class
 *
 * Maps Money objects to MySQL float values. MySQL float can hold up to 19 bits
 * numbers without rounding. Using 20 bits numbers may result in difference
 * between saved and retreived numbers, which is unacceptable.
 *
 * Binnary coding:
 * 0..13 bits - money amount (14 bits)
 * 14..17 bits - currency code (4 bits)
 * 18 bit - indicates that field contains currency code. If 0 - default currency is assumed.
 *
 */
class MoneyMapper
{
    const DEFAULT_NUMERIC_CURRENCY_CODE = 0;

    const AMOUNT_BITS = 14;

    const CURRENCY_BITS = 4;

    protected static $supportedCurrencies = array(
        0 => 'LVL',
        1 => 'EUR',
    );

    /**
     * @param integer $value
     * @return Money\Money
     */
    public function assembleMoneyFromDatabaseValue($value)
    {
        $currencyCode = $this->resolveCurrencyCode($value);
        $amount = $this->resolveAmount($value);

        return new Money($amount, new Currency($currencyCode));
    }

    /**
     * @param Money $money
     * @return integer
     */
    public function convertMoneyToDatabaseValue(Money $money)
    {
        if ($money->getAmount() >= pow(2, self::AMOUNT_BITS)) {
            throw new InvalidArgumentException(
                sprintf(
                    'Value %s does not fit in %s bits (max %s)',
                    $money->getAmount(),
                    self::AMOUNT_BITS,
                    pow(2, self::AMOUNT_BITS) - 1
                )
            );
        }

        $numericCurrencyCode = $this->getNumericCurrencyCodeFromCurrencyCode(
            $money->getCurrency()->getName()
        );
        $databaseValue = 1 << self::AMOUNT_BITS + self::CURRENCY_BITS;
        $databaseValue |= $numericCurrencyCode << self::AMOUNT_BITS;
        $databaseValue |= $money->getAmount();

        $mask = pow(2, self::AMOUNT_BITS) -1;

        return $databaseValue;
    }

    /**
     * @param string $databaseValue
     * @return integer
     */
    protected function resolveAmount($databaseValue)
    {
        if ($this->hasAmountOnly($databaseValue)) {
            $amount = $databaseValue;
        } else {
            $amountMask = pow(2, self::AMOUNT_BITS) -1;
            $amount = intval($databaseValue) & $amountMask;
        }

        return (int) $amount;
    }

    protected function resolveCurrencyCode($databaseValue)
    {
        $numericCurrencyCode = self::DEFAULT_NUMERIC_CURRENCY_CODE;
        if (!$this->hasAmountOnly($databaseValue)) {
            $currencyCodeMask = pow(2, self::CURRENCY_BITS) - 1;
            $numericCurrencyCode = intval($databaseValue) >> self::AMOUNT_BITS & $currencyCodeMask;
        }

        return $this->getCurrencyCodeFromNumericCurrencyCode($numericCurrencyCode);
    }

    public function hasAmountOnly($databaseValue)
    {
        return ($databaseValue & 1 << self::AMOUNT_BITS + self::CURRENCY_BITS) === 0;
    }

    protected function getCurrencyCodeFromNumericCurrencyCode($numericCurrencyCode)
    {
        if (!array_key_exists($numericCurrencyCode, static::$supportedCurrencies)) {
            throw new InvalidArgumentException(
                sprintf('Currency code %s is not supported', $numericCurrencyCode)
            );
        }

        return static::$supportedCurrencies[$numericCurrencyCode];
    }

    protected function getNumericCurrencyCodeFromCurrencyCode($currencyCode)
    {
        $numericCurrencyCode = array_search($currencyCode, static::$supportedCurrencies);

        if ($numericCurrencyCode === false) {
            throw new InvalidArgumentException(
                sprintf('Currency code %s is not supported', $currencyCode)
            );
        }

        return $numericCurrencyCode;
    }
}
