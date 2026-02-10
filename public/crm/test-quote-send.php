<?php
/**
 * Quote Send Diagnostic Tool
 * Tests email and SMS sending functions in isolation
 */
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/smtp_mailer.php';
require_once dirname(__DIR__) . '/includes/sms_gateway.php';

requireLogin();
$user = getCurrentUser();

$results = [];

// Test 1: Check if functions exist
$results['functions_exist'] = [
    'sendCrmEmail' => function_exists('sendCrmEmail'),
    'sendSmsViaMail' => function_exists('sendSmsViaMail'),
    'getDB' => function_exists('getDB'),
];

// Test 2: Get a test quote
try {
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM quotes LIMIT 1");
    $stmt->execute();
    $testQuote = $stmt->fetch(PDO::FETCH_ASSOC);

    $results['test_quote_found'] = !empty($testQuote);
    if ($testQuote) {
        $results['test_quote_id'] = $testQuote['id'];
        $results['test_quote_number'] = $testQuote['quote_number'];
    }
} catch (Exception $e) {
    $results['test_quote_error'] = $e->getMessage();
}

// Test 3: Test email sending
if (!empty($testQuote)) {
    $testEmail = 'test@mowology.ca';
    $testSubject = 'Test Email from Quote System';
    $testBody = '<h2>Test</h2><p>This is a test email from the quote send diagnostic.</p>';

    error_log("=== QUOTE SEND TEST: Starting email test ===");
    $emailResult = sendCrmEmail($testEmail, $testSubject, $testBody, null);
    error_log("=== QUOTE SEND TEST: Email result = " . json_encode(['success' => $emailResult]));

    $results['email_test'] = [
        'recipient' => $testEmail,
        'result' => $emailResult,
        'status' => $emailResult ? 'SUCCESS' : 'FAILED',
    ];
}

// Test 4: Test SMS sending
if (!empty($testQuote)) {
    $testPhone = '6048889273';
    $testMessage = 'Test SMS from Mowology Quote System';

    error_log("=== QUOTE SEND TEST: Starting SMS test ===");
    $smsResult = sendSmsViaMail($testPhone, $testMessage, 'Mowology');
    error_log("=== QUOTE SEND TEST: SMS result = " . json_encode($smsResult));

    $results['sms_test'] = [
        'phone' => $testPhone,
        'message' => $testMessage,
        'success' => $smsResult['success'] ?? false,
        'errors' => $smsResult['errors'] ?? [],
    ];
}

// Test 5: Check mail server
$results['mail_available'] = function_exists('mail');
$results['mail_config'] = [
    'sendmail_path' => ini_get('sendmail_path') ?: 'Not set',
    'smtp' => ini_get('SMTP') ?: 'Not set',
    'smtp_port' => ini_get('SMTP_PORT') ?: 'Not set',
];

$pageTitle = 'Quote Send Diagnostic';
$activePage = 'quotes';
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

          <a href="quotes/index.php" class="mw-back-link">&larr; Back to Quotes</a>

          <div class="card mt-4">
              <div class="card-header">
                  <h2>Quote Send Diagnostic Test Results</h2>
              </div>
              <div class="card-body">
                  <div class="alert alert-info">
                      <strong>Test Status:</strong> Diagnostic tests completed. Check PHP error log for detailed output.
                  </div>

                  <h4>1. Function Availability</h4>
                  <table class="table table-sm">
                      <tr>
                          <td>sendCrmEmail exists?</td>
                          <td><span class="badge badge-<?php echo $results['functions_exist']['sendCrmEmail'] ? 'success' : 'danger'; ?>">
                              <?php echo $results['functions_exist']['sendCrmEmail'] ? 'YES' : 'NO'; ?>
                          </span></td>
                      </tr>
                      <tr>
                          <td>sendSmsViaMail exists?</td>
                          <td><span class="badge badge-<?php echo $results['functions_exist']['sendSmsViaMail'] ? 'success' : 'danger'; ?>">
                              <?php echo $results['functions_exist']['sendSmsViaMail'] ? 'YES' : 'NO'; ?>
                          </span></td>
                      </tr>
                      <tr>
                          <td>getDB exists?</td>
                          <td><span class="badge badge-<?php echo $results['functions_exist']['getDB'] ? 'success' : 'danger'; ?>">
                              <?php echo $results['functions_exist']['getDB'] ? 'YES' : 'NO'; ?>
                          </span></td>
                      </tr>
                  </table>

                  <h4>2. Test Quote Found</h4>
                  <table class="table table-sm">
                      <tr>
                          <td>Quote Found?</td>
                          <td><span class="badge badge-<?php echo $results['test_quote_found'] ? 'success' : 'danger'; ?>">
                              <?php echo $results['test_quote_found'] ? 'YES' : 'NO'; ?>
                          </span></td>
                      </tr>
                      <?php if ($results['test_quote_found']): ?>
                      <tr>
                          <td>Quote ID</td>
                          <td><?php echo htmlspecialchars($results['test_quote_id']); ?></td>
                      </tr>
                      <tr>
                          <td>Quote Number</td>
                          <td><?php echo htmlspecialchars($results['test_quote_number']); ?></td>
                      </tr>
                      <?php endif; ?>
                      <?php if (!empty($results['test_quote_error'])): ?>
                      <tr>
                          <td>Error</td>
                          <td><span class="badge badge-danger"><?php echo htmlspecialchars($results['test_quote_error']); ?></span></td>
                      </tr>
                      <?php endif; ?>
                  </table>

                  <h4>3. Mail Server Configuration</h4>
                  <table class="table table-sm">
                      <tr>
                          <td>mail() available?</td>
                          <td><span class="badge badge-<?php echo $results['mail_available'] ? 'success' : 'danger'; ?>">
                              <?php echo $results['mail_available'] ? 'YES' : 'NO'; ?>
                          </span></td>
                      </tr>
                      <tr>
                          <td>sendmail_path</td>
                          <td><code><?php echo htmlspecialchars($results['mail_config']['sendmail_path']); ?></code></td>
                      </tr>
                      <tr>
                          <td>SMTP</td>
                          <td><code><?php echo htmlspecialchars($results['mail_config']['smtp']); ?></code></td>
                      </tr>
                      <tr>
                          <td>SMTP Port</td>
                          <td><code><?php echo htmlspecialchars($results['mail_config']['smtp_port']); ?></code></td>
                      </tr>
                  </table>

                  <?php if (!empty($results['email_test'])): ?>
                  <h4>4. Email Test Result</h4>
                  <table class="table table-sm">
                      <tr>
                          <td>To</td>
                          <td><?php echo htmlspecialchars($results['email_test']['recipient']); ?></td>
                      </tr>
                      <tr>
                          <td>Status</td>
                          <td><span class="badge badge-<?php echo $results['email_test']['result'] ? 'success' : 'danger'; ?>">
                              <?php echo $results['email_test']['status']; ?>
                          </span></td>
                      </tr>
                  </table>
                  <?php endif; ?>

                  <?php if (!empty($results['sms_test'])): ?>
                  <h4>5. SMS Test Result</h4>
                  <table class="table table-sm">
                      <tr>
                          <td>Phone</td>
                          <td><?php echo htmlspecialchars($results['sms_test']['phone']); ?></td>
                      </tr>
                      <tr>
                          <td>Message</td>
                          <td><?php echo htmlspecialchars($results['sms_test']['message']); ?></td>
                      </tr>
                      <tr>
                          <td>Success?</td>
                          <td><span class="badge badge-<?php echo $results['sms_test']['success'] ? 'success' : 'danger'; ?>">
                              <?php echo $results['sms_test']['success'] ? 'YES' : 'NO'; ?>
                          </span></td>
                      </tr>
                      <?php if (!empty($results['sms_test']['errors'])): ?>
                      <tr>
                          <td>Errors</td>
                          <td><pre><?php echo htmlspecialchars(json_encode($results['sms_test']['errors'], JSON_PRETTY_PRINT)); ?></pre></td>
                      </tr>
                      <?php endif; ?>
                  </table>
                  <?php endif; ?>

                  <div class="alert alert-warning mt-4">
                      <strong>Next Steps:</strong>
                      <ol>
                          <li>Check the PHP error log for lines starting with "QUOTE SEND TEST"</li>
                          <li>Note the results above and in the log</li>
                          <li>If email test shows SUCCESS but SMS shows FAILURE with errors, the issue is SMS consent</li>
                          <li>If email test shows FAILED, the issue is with the mail() function itself</li>
                      </ol>
                  </div>
              </div>
          </div>

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>
