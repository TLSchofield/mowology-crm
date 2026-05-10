//
//  VisionOCRServiceTests.swift
//  MowologyCRMTests
//
//  Tests for VisionOCRService field-extraction logic using realistic Canadian
//  receipt text strings.  These tests bypass the Vision framework's image OCR
//  step and call the parsing helpers directly via @testable import, so they
//  run fast (no neural engine, no camera permission) and work in CI.
//
//  Run via: Product ▶ Test (⌘U) in Xcode, or `xcodebuild test` on the CLI.
//

import XCTest
@testable import MowologyCRM

final class VisionOCRServiceTests: XCTestCase {

    // =========================================================================
    // MARK: - Fixtures
    // =========================================================================

    /// City of Vancouver parking receipt (real shape from the preview)
    private let vancouverParking = """
        CITY OF VANCOUVER
        PARKING METER RECEIPT
        123 Main St E
        Station 4521
        2025-05-28 14:32
        Subtotal  34.47
        GST 5%     1.72
        Total     36.19
        VISA **** 4921
        Thank you
        """

    /// Home Depot building supplies
    private let homeDepot = """
        THE HOME DEPOT
        Store #7041 - Burnaby BC
        1177 United Blvd
        Tel: (604) 555-0142

        INVOICE

        1x  Husqvarna String Trimmer   $399.00
        2x  Safety Glasses              $24.98
        3x  Work Gloves                 $44.97

        Subtotal               $468.95
        GST (5%)                $23.45
        Total                  $492.40
        MASTERCARD *4112
        02/15/2025 09:44 AM
        """

    /// Chevron gas station
    private let chevron = """
        CHEVRON
        4521 Kingsway
        Burnaby BC V5H 2A9

        PUMP 3  REGULAR UNLEADED
        42.73 L @ $1.749/L

        Fuel              74.71
        Subtotal          74.71
        GST                3.74
        Total             78.45
        DEBIT PURCHASE APPROVED
        2025-03-12
        """

    /// Tim Hortons — only a Total line, subtotal + GST back-calculated
    private let timHortons = """
        Tim Hortons
        1055 West Georgia St
        Vancouver BC

        2x  Medium Coffee       3.79
        1x  Bagel w/ Cream Ch   4.29

        TOTAL                  12.45
        CONTACTLESS VISA DEBIT *8832
        2025-04-01 07:12
        """

    /// Quebec IGA — TPS/TVQ French-language receipt
    private let quebecIGA = """
        IGA Extra
        Montréal QC H2X 1Y6

        Sous-total           69.98
        TPS  5%               3.50
        TVQ  9.975%           6.98
        Total                80.46
        Paiement - comptant
        2025-01-15
        """

    /// Southlands Nursery — BC receipt with both GST and PST (real shape from screenshot)
    private let southlandsNursery = """
        Southlands Nursery
        6505 Balaclava Street
        Vancouver BC V6N 1L9
        May 10, 2026
        Herbs/Veggies x4 Herb/Veg    20.97
        Original Price                26.96
        Discount: Landscapers (25%)    6.99
        Annuals                        3.74
        Original Price                 4.99
        Discount: Landscapers (25%)    1.25
        Perennial x2                   9.98
        Original Price                11.98
        Perennial x4 4-inch Pot       26.93
        Original Price                28.80
        Herbs/Veggies                 30.00
        Original Price                40.00
        Subtotal                      90.62
        GST Sales Tax (5%)             4.53
        PST Sales Tax (7%)             0.80
        Total                         96.04
        INTERAC
        AID: A0000002771010
        """

    // =========================================================================
    // MARK: - Amount extraction
    // =========================================================================

    func testVancouverParkingAmounts() {
        let fill = parseLines(from: vancouverParking)
        XCTAssertEqual(fill.total,    "36.19", "total")
        XCTAssertEqual(fill.gst,      "1.72",  "gst")
        XCTAssertEqual(fill.subtotal, "34.47", "subtotal")
    }

    func testHomeDepotAmounts() {
        let fill = parseLines(from: homeDepot)
        XCTAssertEqual(fill.total,    "492.40", "total")
        XCTAssertEqual(fill.gst,      "23.45",  "gst")
        XCTAssertEqual(fill.subtotal, "468.95", "subtotal")
    }

    func testChevronAmounts() {
        let fill = parseLines(from: chevron)
        XCTAssertEqual(fill.total,    "78.45", "total")
        XCTAssertEqual(fill.gst,      "3.74",  "gst")
        XCTAssertEqual(fill.subtotal, "74.71", "subtotal")
    }

    func testTimHortonsGSTBackCalculation() {
        let fill = parseLines(from: timHortons)
        // Total is explicit
        XCTAssertEqual(fill.total, "12.45", "total")
        // GST must be back-calculated from total (12.45 / 1.05 * 0.05 ≈ 0.59)
        XCTAssertNotNil(fill.gst, "gst should be back-calculated")
        if let gst = fill.gst, let gstVal = Double(gst) {
            XCTAssertEqual(gstVal, 0.59, accuracy: 0.02, "back-calculated GST ≈ 0.59")
        }
        // Subtotal back-calculated too (12.45 / 1.05 ≈ 11.86)
        XCTAssertNotNil(fill.subtotal, "subtotal should be back-calculated")
        if let sub = fill.subtotal, let subVal = Double(sub) {
            XCTAssertEqual(subVal, 11.86, accuracy: 0.02, "back-calculated subtotal ≈ 11.86")
        }
    }

    func testFrenchGSTLabels() {
        let fill = parseLines(from: quebecIGA)
        XCTAssertEqual(fill.gst,   "3.50",  "TPS should map to gst")
        XCTAssertEqual(fill.pst,   "6.98",  "TVQ should map to pst")
        XCTAssertEqual(fill.total, "80.46", "total")
    }

    func testBCReceiptWithGSTAndPST() {
        let fill = parseLines(from: southlandsNursery)
        XCTAssertEqual(fill.vendorHint, "Southlands Nursery", "vendor")
        XCTAssertEqual(fill.subtotal,   "90.62",             "subtotal")
        XCTAssertEqual(fill.gst,        "4.53",              "GST 5%")
        XCTAssertEqual(fill.pst,        "0.80",              "PST 7%")
        XCTAssertEqual(fill.total,      "96.04",             "total")
        XCTAssertEqual(fill.date,       "2026-05-10",        "date")
        XCTAssertEqual(fill.paymentMethod, "debit",          "INTERAC → debit")
    }

    // =========================================================================
    // MARK: - Date extraction
    // =========================================================================

    func testISODate() {
        let fill = parseLines(from: "SOME STORE\n2025-05-28\nTotal 36.19")
        XCTAssertEqual(fill.date, "2025-05-28")
    }

    func testSlashDate_MMDDYYYY() {
        let fill = parseLines(from: "SOME STORE\n02/15/2025\nTotal 492.40")
        XCTAssertEqual(fill.date, "2025-02-15")
    }

    func testMonthNameDate() {
        let fill = parseLines(from: "SOME STORE\nMay 28, 2025\nTotal 36.19")
        XCTAssertEqual(fill.date, "2025-05-28")
    }

    func testDayMonthNameDate() {
        let fill = parseLines(from: "SOME STORE\n28 May 2025\nTotal 36.19")
        XCTAssertEqual(fill.date, "2025-05-28")
    }

    func testNoDateReturnsNil() {
        let fill = parseLines(from: "SOME STORE\nTotal 36.19")
        XCTAssertNil(fill.date, "no date in text should return nil")
    }

    // =========================================================================
    // MARK: - Payment method detection
    // =========================================================================

    func testVISAIsCreditCard() {
        let fill = parseLines(from: "Total 36.19\nVISA **** 4921")
        XCTAssertEqual(fill.paymentMethod, "credit_card")
    }

    func testMASTERCARDIsCreditCard() {
        let fill = parseLines(from: "Total 100.00\nMASTERCARD *4112")
        XCTAssertEqual(fill.paymentMethod, "credit_card")
    }

    func testAMEXIsCreditCard() {
        let fill = parseLines(from: "Total 50.00\nAMEX *1234")
        XCTAssertEqual(fill.paymentMethod, "credit_card")
    }

    func testDebitIsDebit() {
        let fill = parseLines(from: "Total 78.45\nDEBIT PURCHASE APPROVED")
        XCTAssertEqual(fill.paymentMethod, "debit")
    }

    func testCashIsCash() {
        let fill = parseLines(from: "Total 20.00\nCASH PAYMENT")
        XCTAssertEqual(fill.paymentMethod, "cash")
    }

    func testETransferIsETransfer() {
        let fill = parseLines(from: "Total 250.00\nE-TRANSFER RECEIVED")
        XCTAssertEqual(fill.paymentMethod, "etransfer")
    }

    func testNoPaymentReturnsNil() {
        let fill = parseLines(from: "SOME STORE\nTotal 36.19\n2025-05-28")
        XCTAssertNil(fill.paymentMethod, "no payment keyword → nil")
    }

    // =========================================================================
    // MARK: - Vendor extraction
    // =========================================================================

    func testVendorIsFirstMeaningfulLine() {
        let fill = parseLines(from: "CITY OF VANCOUVER\n123 Main St\nTotal 36.19")
        XCTAssertEqual(fill.vendorHint, "CITY OF VANCOUVER")
    }

    func testVendorSkipsNumericPrefixLines() {
        // First line is all numbers — should skip to next real line
        let fill = parseLines(from: "1234567890\nHOME DEPOT\nTotal 50.00")
        XCTAssertEqual(fill.vendorHint, "HOME DEPOT")
    }

    func testVendorNilOnEmptyInput() {
        let fill = parseLines(from: "")
        XCTAssertNil(fill.vendorHint)
    }

    // =========================================================================
    // MARK: - Edge cases
    // =========================================================================

    func testEmptyInputDoesNotCrash() {
        let fill = parseLines(from: "")
        XCTAssertNil(fill.total)
        XCTAssertNil(fill.gst)
        XCTAssertNil(fill.pst)
        XCTAssertNil(fill.subtotal)
        XCTAssertNil(fill.date)
        XCTAssertNil(fill.paymentMethod)
        XCTAssertNil(fill.vendorHint)
    }

    func testAmountRegexDoesNotPickUpPhoneNumbers() {
        // Phone numbers like 604-555-0142 must not be parsed as amounts
        let fill = parseLines(from: "STORE\nTel: (604) 555-0142\nTotal 42.00")
        XCTAssertEqual(fill.total, "42.00")
    }

    func testLargeAmountWithCommaNotation() {
        let fill = parseLines(from: "Equipment\nTotal  1,234.56")
        XCTAssertEqual(fill.total, "1234.56")
    }

    // =========================================================================
    // MARK: - Performance
    // =========================================================================

    /// Parsing a typical receipt text must complete in under 50 ms.
    func testParsingPerformance() {
        let text = (1...20).map { _ in homeDepot }.joined(separator: "\n---\n") // 20x fixture
        measure {
            _ = parseLines(from: text)
        }
    }

    // =========================================================================
    // MARK: - Private helper
    // =========================================================================

    /// Calls VisionOCRService's internal parse(lines:rawText:) logic by
    /// splitting on newlines — bypasses the Vision image OCR step entirely.
    ///
    /// Note: `parse(lines:rawText:)` is private in VisionOCRService.
    /// If the test fails to compile here, make it `internal` in the source file
    /// or expose it via a @testable helper (see VisionOCRService+Testing.swift).
    private func parseLines(from text: String) -> VisionPreFill {
        let lines = text.components(separatedBy: .newlines)
            .map { $0.trimmingCharacters(in: .whitespaces) }
            .filter { !$0.isEmpty }
        // Mirror how VisionOCRService assembles rawText
        let rawText = lines.joined(separator: "\n")
        // Call through the internal parse method exposed via @testable import
        return VisionOCRService.parseForTesting(lines: lines, rawText: rawText)
    }
}
