<?php

namespace frontend\modules\reservation\services;

use Yii;
use common\models\boat\Boat;
use frontend\modules\reservation\services\CalendarService as Calendar;

class PriceCalculationService
{
    /** @var Boat Leased boat */
    protected $boat;

    /** @var \DateTime Check in date */
    protected $checkIn;

    /** @var \DateTime  Check out date */
    protected $checkOut;

    /** @var int Number of the boat guests */
    protected $guests;

    /** @var string List of selected boat extras separated by coma */
    protected $extras;

    /** @var string Target currency for price displaying */
    protected $targetCurrency;

    /** @var float|null Rental price before applying of the discount */
    protected $rawRentalPrice = null;

    /** @var float|null Discount value (from 0 to 1) */
    protected $discount = null;

    /** @var float|null Rental price after applying of the discount */
    protected $rentalPrice = null;

    /** @var float|null Daily price for the boat renting after applying the discount */
    protected $rentalDailyPrice = null;

    /** @var float|null Daily price for the all services */
    protected $dailyPrice = null;

    /** @var float|null Daily price for the all services */
    protected $dailyPaidPrice = null;

    /** @var float|null Service fee value (from 0 to 1) */
    protected $fee = null;

    /** @var float|null Total price which will be paid via service */
    protected $totalPaidPrice = null;

    /** @var float|null Total price that should be paid at all */
    protected $totalPrice = null;

    /** @var float|null Total price that should be paid at all */
    protected $cleanTotalPaidPrice = null;

    /** @var ExtraPriceService|null Extra pricing calculation service instance */
    protected $extraPriceService = null;

    public function __construct(
        Boat $boat,
        ?\DateTime $checkIn,
        ?\DateTime $checkOut,
        ?int $guests,
        ?string $extras,
        string $targetCurrency
    ) {
        $this->boat = $boat;
        $this->checkIn = $checkIn;
        $this->checkOut = $checkOut;
        $this->guests = $guests;
        $this->extras = $extras;
        $this->targetCurrency = $targetCurrency;
    }

    public function get($name)
    {
        $getter = 'get' . ucfirst($name);

        if ($this->$name === null && method_exists(__CLASS__, $getter)) {
            $this->$name = $this->$getter();
        }

        return $this->$name;
    }

    /**
     * Return raw rental price before applying the discount
     *
     * @return float
     */
    public function getRawRentalPrice(): float
    {
        $converter = Yii::$app->currency;

        $rawRentalPrice = 0;

        if ($this->checkIn === null || $this->checkOut === null) {
            return $rawRentalPrice;
        }

        $calendar = new Calendar($this->checkIn, $this->checkOut);

        $fixedPeriodPrices = $this->boat->getFixedPeriodPrices(
            $this->checkIn->format('Y-m-d'),
            $this->checkOut->format('Y-m-d')
        );

        /*
         * Custom monthly prices
         */
        if (!empty($fixedPeriodPrices['monthly'])) {
            foreach ($fixedPeriodPrices['monthly'] as $month) {
                $from = new \DateTime($month->date_from);
                $to = (new \DateTime($month->date_to))->sub(new \DateInterval('P1D'));
                if ($calendar->checkPeriodIntegrity($from->format('Y-m-d'), $to->format('Y-m-d'))) {
                    $rawRentalPrice += $converter->convert(
                        $month->price_currency,
                        $this->targetCurrency,
                        $month->price
                    );
                    $calendar->removePeriod($from->format('Y-m-d'), $to->format('Y-m-d'));
                }
            }
        }

        /*
         * Monthly prices
         */
        if (!empty($this->boat->prices->monthly_price)) {
            $daysInMonth = Yii::$app->config->get('reservation.days_in.month');
            while ($start = $calendar->issetSolidPeriod($daysInMonth)) {
                $rawRentalPrice += $converter->convert(
                    $this->boat->prices->monthly_price_currency,
                    $this->targetCurrency,
                    $this->boat->prices->monthly_price
                );
                $end = (new \Datetime($start))->add(new \DateInterval('P' . $daysInMonth . 'D'));
                $calendar->removePeriod($start, $end->format('Y-m-d'));
            }
        }

        /*
         * Custom weekly prices
         */
        if (!empty($fixedPeriodPrices['weekly'])) {
            foreach ($fixedPeriodPrices['weekly'] as $week) {
                $from = new \DateTime($week->date_from);
                $to = (new \DateTime($week->date_to))->sub(new \DateInterval('P1D'));
                if ($calendar->checkPeriodIntegrity($from->format('Y-m-d'), $to->format('Y-m-d'))) {
                    $rawRentalPrice += $converter->convert(
                        $week->price_currency,
                        $this->targetCurrency,
                        $week->price
                    );
                    $calendar->removePeriod($from->format('Y-m-d'), $to->format('Y-m-d'));
                }
            }
        }

        /*
         * Weekly prices
         */
        if (!empty($this->boat->prices->weekly_price)) {
            $daysInWeek = Yii::$app->config->get('reservation.days_in.week');
            while ($start = $calendar->issetSolidPeriod($daysInWeek)) {
                $rawRentalPrice += $converter->convert(
                    $this->boat->prices->weekly_price_currency,
                    $this->targetCurrency,
                    $this->boat->prices->weekly_price
                );
                $end = (new \DateTime($start))->add(new \DateInterval('P' . $daysInWeek . 'D'));
                $calendar->removePeriod($start, $end->format('Y-m-d'));
            }
        }

        /*
         * Custom period prices
         */
        $customPrices = $this->boat->getCustomPeriodPrices(
            $this->checkIn->format('Y-m-d'),
            $this->checkOut->format('Y-m-d')
        );

        foreach ($customPrices as $day => $info) {
            if (!empty($info) && $calendar->checkPeriodIntegrity($day, $day)) {
                $rawRentalPrice += $converter->convert(
                    $info['price_currency'],
                    $this->targetCurrency,
                    $info['price']
                );
                $calendar->removeDay($day);
            }
        }

        /*
         * Daily prices
         */
        $rawRentalPrice += $converter->convert(
            $this->boat->prices->daily_price_currency,
            $this->targetCurrency,
            $this->boat->prices->daily_price
        ) * $calendar->getDaysCount();

        return $rawRentalPrice;
    }

    /**
     * Return discount value
     *
     * @return float
     */
    public function getDiscount(): float
    {
        if ($this->boat->isBM()) {
            try {
                $response = Yii::$app->bookingManager->getResourceDiscount(
                    $this->boat->resource_id,
                    $this->checkIn->format('Y-m-d\TH:i:s'),
                    $this->checkOut->format('Y-m-d\TH:i:s')
                );
                $discount = (float)$response['discount'] / 100;
            } catch (\Throwable $e) {
                $discount = 0;
            }
        } elseif ($this->boat->isBB()) {
            $discount = 0;
        } else {
            $calendar = new Calendar($this->checkIn, $this->checkOut);

            $customPeriodDiscount = $this->boat->getCustomPeriodDiscount(
                $this->checkIn->format('Y-m-d'),
                $this->checkOut->format('Y-m-d')
            );

            /*
             * Custom monthly discount
             */
            if (!empty($customPeriodDiscount['monthly'])) {
                foreach ($customPeriodDiscount['monthly'] as $month) {
                    $from = new \DateTime($month->date_from);
                    $to = (new \DateTime($month->date_to))->sub(new \DateInterval('P1D'));
                    if ($calendar->checkPeriodIntegrity($from->format('Y-m-d'), $to->format('Y-m-d'))) {
                        return (float)$month->discount / 100;
                    }
                }
            }

            /*
             * Monthly discount
             */
            if ($this->boat->discount && $this->boat->discount->monthly_discount) {
                $daysInMonth = Yii::$app->config->get('reservation.days_in.month');
                if ($calendar->isWholeMonth($daysInMonth)) {
                    return $this->boat->discount->monthly_discount / 100;
                }
            }

            /*
             * Custom weekly discount
             */
            if (!empty($customPeriodDiscount['weekly'])) {
                foreach ($customPeriodDiscount['weekly'] as $week) {
                    $from = new \DateTime($week->date_from);
                    $to = (new \DateTime($week->date_to))->sub(new \DateInterval('P1D'));
                    if ($calendar->checkPeriodIntegrity($from->format('Y-m-d'), $to->format('Y-m-d'))) {
                        return (float)$week->discount / 100;
                    }
                }
            }

            /*
             * Weekly discount
             */
            if ($this->boat->discount && $this->boat->discount->weekly_discount) {
                $daysInWeek = Yii::$app->config->get('reservation.days_in.week');
                if ($calendar->issetSolidPeriod($daysInWeek)) {
                    return $this->boat->discount->weekly_discount / 100;
                }
            }

            /*
             * General discount
             */
            $discount = $this->boat->discount ? $this->boat->discount->daily_discount / 100 : 0;
        }

        return $discount;
    }

    /**
     * Return rental price after applying the discount
     *
     * @return float
     */
    public function getRentalPrice(): float
    {
        return $this->get('rawRentalPrice') * (1 - $this->get('discount'));
    }

    /**
     * Return service fee value for renter
     *
     * @return float
     */
    public function getFee(): float
    {
        if ($this->boat->isBM()) {
            return 0;
        } elseif ($this->boat->isBB()) {
            return $this->boat->commission / 100;
        } else {
            if ($this->boat->commission) {
                return $this->boat->commission / 100;
            }
            if ($this->boat->owner->fee) {
                return $this->boat->owner->fee / 100;
            }

            return Yii::$app->config->get('service.fee', 12) / 100;
        }
    }

    /**
     * Return service fee value for owner
     *
     * @return float
     */
    public function getFeeForOwner(): float
    {
        if ($this->boat->isExternalBoat()) {
            $fee = 0;
        } else {
            $fee = Yii::$app->config->get('service.fee_owner', 3) / 100;
        }

        return $fee;
    }

    /**
     * Return 100% total price which will be paid via our service
     *
     * @return float
     */
    protected function getCleanTotalPaidPrice(): float
    {
        $price = $this->get('rentalPrice') +
            $this->get('extraPriceService')->getSubtotals()['optional-invoice'] +
            $this->get('extraPriceService')->getSubtotals()['obligatory-invoice'];

        return $price;
    }

    /**
     * Return total price which will be paid via service considering settings from pricing page
     * (Required Payment and applied to the first day of booking)
     *
     * @return float
     */
    public function getTotalPaidPrice(): float
    {
        $price = $this->get('cleanTotalPaidPrice');

        if ($this->boat->prices->is_required_payment_first_day) {
            $interval = $this->checkOut->diff($this->checkIn)->d;
            if ($interval > 0) {
                $price = $price / $interval;
            }
        }

        if ($this->boat->prices->required_payment !== 100) {
            $price *= $this->boat->prices->required_payment / 100;
        }

        if ($this->boat->prices && $this->boat->prices->isDepositPayable()) {
            $price += $this->boat->prices->deposit;
        }

        return $price + $this->calculatePriceFee();
    }

    /**
     * Return total price that should be paid at all
     *
     * @return float
     */
    public function getTotalPrice(): float
    {
        $price = $this->get('rentalPrice') + $this->get('extraPriceService')->getSubtotals()['total'];

        if ($this->boat->prices && $this->boat->prices->isDepositPayable()) {
            $price += $this->boat->prices->deposit;
        }

        return $price + $this->calculatePriceFee();
    }

    public function calculatePriceFee(): int
    {
        if (!$this->boat->isBR() || $this->getFee() === 0) {
            return 0;
        }

        $price = $this->get('cleanTotalPaidPrice');
        $priceWithFee = $price * (1 + $this->getFee());

        return $priceWithFee - $price;
    }

    public function calculatePriceFeeForOwner(): int
    {
        if (!$this->boat->isBR() || $this->getFeeForOwner() === 0) {
            return 0;
        }

        $price = $this->get('cleanTotalPaidPrice');
        $priceWithFee = $price * (1 + $this->getFeeForOwner());

        return $priceWithFee - $price;
    }

    /**
     * Return daily price for the boat renting after applying the discount
     *
     * @return int
     */
    public function getRentalDailyPrice(): int
    {
        $price = 0;
        if ($this->checkOut !== null && $this->checkIn !== null) {
            $interval = $this->checkOut->diff($this->checkIn)->d;
            if ($interval > 0) {
                $price = $this->get('rentalPrice') / $interval;
            }
        } elseif ($this->boat->prices && $this->boat->prices->daily_price > 0) {
            $price = Yii::$app->currency->convert(
                $this->boat->prices->daily_price_currency,
                $this->targetCurrency,
                $this->boat->prices->daily_price
            );
        } else {
            $price = 0;
        }

        return $price;
    }

    /**
     * Return daily price for total which will be paid via service
     *
     * @return int
     */
    public function getDailyPaidPrice(): int
    {
        if ($this->checkOut !== null && $this->checkIn !== null && $this->checkOut->diff($this->checkIn)->d > 0) {
            $price = $this->get('totalPaidPrice') / $this->checkOut->diff($this->checkIn)->d;
        } else {
            $price = $this->get('rentalDailyPrice');
        }

        return $price;
    }

    /**
     * Return daily price for total which will be paid at all
     *
     * @return float
     */
    public function getDailyPrice(): float
    {
        if ($this->checkOut !== null && $this->checkIn !== null && $this->checkOut->diff($this->checkIn)->d > 0) {
            return $this->get('totalPrice') / $this->checkOut->diff($this->checkIn)->d;
        } else {
            return $this->get('rentalDailyPrice');
        }
    }

    /**
     * Return Extra price calculation service initialized with current params
     *
     * @return ExtraPriceService
     */
    public function getExtraPriceService(): ExtraPriceService
    {
        return new ExtraPriceService(
            $this->extras,
            $this->boat,
            $this->guests,
            $this->checkIn && $this->checkOut ? $this->checkOut->diff($this->checkIn)->d : 0,
            $this->targetCurrency
        );
    }
}
