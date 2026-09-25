<?php declare(strict_types=1);

namespace SourceFolioRecords\Tests;

use PHPUnit\Framework\TestCase;
use SourceFolioRecords\RowValidation;

final class RowValidationTest extends TestCase {
    public function testNoErrorsIsNotFatal(): void {
        $this->assertFalse(RowValidation::hasFatalErrors([]));
    }

    public function testAWarningOnlyIsNotFatal(): void {
        $errors = ["Row 2: Warning: 'statisticalCodeIds[0]' references statistical code name 'Missing', which was not found in the tenant's statistical codes"];

        $this->assertFalse(RowValidation::hasFatalErrors($errors));
    }

    public function testAMissingRequiredFieldIsFatal(): void {
        $errors = ["Row 2: missing required field 'title'"];

        $this->assertTrue(RowValidation::hasFatalErrors($errors));
    }

    public function testAMixOfWarningsAndAFatalErrorIsFatal(): void {
        $errors = [
            "Row 2: Warning: 'statisticalCodeIds[0]' references statistical code name 'Missing', which was not found in the tenant's statistical codes",
            "Row 2: missing required field 'title'",
        ];

        $this->assertTrue(RowValidation::hasFatalErrors($errors));
    }

    public function testIsWarningDistinguishesWarningsFromErrors(): void {
        $this->assertTrue(RowValidation::isWarning("Row 2: Warning: 'statisticalCodeIds[0]' references statistical code name 'Missing', which was not found in the tenant's statistical codes"));
        $this->assertFalse(RowValidation::isWarning("Row 2: missing required field 'title'"));
    }
}
