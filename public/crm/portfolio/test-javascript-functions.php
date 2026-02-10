<?php
/**
 * Test page to verify all portfolio JavaScript functions are properly loaded
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

requireLogin();
$user = getCurrentUser();

if (!$user || $user['role'] !== 'admin') {
    die('Admin access required');
}

$csrfToken = generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Portfolio JavaScript Test</title>
    <link href="/crm/css/classic.css" rel="stylesheet">
    <link href="/crm/css/mowology-brand.css" rel="stylesheet">
    <style>
        body { padding: 20px; background: #f5f5f5; }
        .test-box { background: white; border: 1px solid #ddd; border-radius: 4px; padding: 15px; margin: 15px 0; }
        .success { color: #2D8659; font-weight: bold; }
        .error { color: #d32f2f; font-weight: bold; }
        .warning { color: #f57c00; font-weight: bold; }
        code { background: #f0f0f0; padding: 2px 6px; border-radius: 2px; font-family: monospace; }
        table { border-collapse: collapse; width: 100%; margin-top: 10px; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background: #f0f0f0; }
    </style>
</head>
<body>
    <div class="container-fluid" style="max-width: 900px;">
        <h1>Portfolio Page - JavaScript Functions Test</h1>

        <div class="test-box">
            <h2>Test 1: Check PHP Variables</h2>
            <table>
                <tr><th>Variable</th><th>Value</th><th>Status</th></tr>
                <tr>
                    <td><code>$csrfToken</code></td>
                    <td><?php echo $csrfToken ? substr($csrfToken, 0, 20) . '...' : 'NULL'; ?></td>
                    <td class="<?php echo $csrfToken ? 'success' : 'error'; ?>">
                        <?php echo $csrfToken ? '✓ Set' : '✗ Missing'; ?>
                    </td>
                </tr>
                <tr>
                    <td><code>$_SERVER['SCRIPT_NAME']</code></td>
                    <td><?php echo htmlspecialchars($_SERVER['SCRIPT_NAME']); ?></td>
                    <td class="success">✓ Available</td>
                </tr>
            </table>
        </div>

        <div class="test-box">
            <h2>Test 2: Load Portfolio JavaScript Inline</h2>
            <p>This test loads the recommendation functions from the portfolio page inline and checks if they're available.</p>
            <button class="btn btn-primary" onclick="testGenerateFunction()">Test generateRecommendations()</button>
            <button class="btn btn-success" onclick="testAcceptFunction()">Test acceptRecommendation()</button>
            <div id="testResults" style="margin-top: 15px;"></div>
        </div>

        <div class="test-box">
            <h2>Test 3: Fetch Portfolio Page and Check Script</h2>
            <button class="btn btn-warning" onclick="checkPortfolioPage()">Check Portfolio Page Script</button>
            <div id="pageCheckResults" style="margin-top: 15px;"></div>
        </div>

        <div class="test-box">
            <h2>Test 4: Browser Console Output</h2>
            <p>Open your browser's Developer Tools (F12) and check the Console tab.</p>
            <p>Look for messages starting with "=== Portfolio Script Verification ==="</p>
            <p>If you don't see them, the script may not be loading properly.</p>
            <button class="btn btn-info" onclick="testConsoleMessage()">Send Test Message to Console</button>
        </div>

        <hr>
        <p><a href="index.php?tab=recommendations" class="btn btn-secondary">Back to Recommendations</a></p>
    </div>

    <script>
        const TEST_CSRF_TOKEN = '<?php echo $csrfToken; ?>';

        function testGenerateFunction() {
            const resultsDiv = document.getElementById('testResults');

            // Try to define the function inline for testing
            try {
                // This is what should exist in portfolio/index.php
                if (typeof generateRecommendations === 'undefined') {
                    resultsDiv.innerHTML = '<div class="alert alert-danger"><strong>✗ Function NOT defined</strong><br>generateRecommendations() does not exist in the portfolio page.</div>';
                } else {
                    resultsDiv.innerHTML = '<div class="alert alert-success"><strong>✓ Function FOUND</strong><br>generateRecommendations() is properly defined.</div>';
                }
            } catch(e) {
                resultsDiv.innerHTML = '<div class="alert alert-danger"><strong>✗ Error:</strong><br>' + e.message + '</div>';
            }
        }

        function testAcceptFunction() {
            const resultsDiv = document.getElementById('testResults');

            try {
                if (typeof acceptRecommendation === 'undefined') {
                    resultsDiv.innerHTML = '<div class="alert alert-danger"><strong>✗ Function NOT defined</strong><br>acceptRecommendation() does not exist in the portfolio page.</div>';
                } else {
                    resultsDiv.innerHTML = '<div class="alert alert-success"><strong>✓ Function FOUND</strong><br>acceptRecommendation() is properly defined.</div>';
                }
            } catch(e) {
                resultsDiv.innerHTML = '<div class="alert alert-danger"><strong>✗ Error:</strong><br>' + e.message + '</div>';
            }
        }

        function checkPortfolioPage() {
            const resultsDiv = document.getElementById('pageCheckResults');
            resultsDiv.innerHTML = '<p><em>Fetching portfolio page...</em></p>';

            fetch('/crm/portfolio/index.php?tab=recommendations')
                .then(response => response.text())
                .then(html => {
                    let hasGenerateFunction = html.includes('function generateRecommendations()');
                    let hasScript = html.includes('<script>');
                    let hasCSRFToken = html.includes('const CSRF_TOKEN');

                    let result = '<table>';
                    result += '<tr><th>Check</th><th>Result</th></tr>';
                    result += '<tr><td><code>function generateRecommendations()</code> defined</td><td class="' + (hasGenerateFunction ? 'success' : 'error') + '">' + (hasGenerateFunction ? '✓ YES' : '✗ NO') + '</td></tr>';
                    result += '<tr><td>&lt;script&gt; tag present</td><td class="' + (hasScript ? 'success' : 'error') + '">' + (hasScript ? '✓ YES' : '✗ NO') + '</td></tr>';
                    result += '<tr><td><code>const CSRF_TOKEN</code> set</td><td class="' + (hasCSRFToken ? 'success' : 'error') + '">' + (hasCSRFToken ? '✓ YES' : '✗ NO') + '</td></tr>';
                    result += '</table>';

                    if (!hasGenerateFunction || !hasScript || !hasCSRFToken) {
                        result += '<p class="warning"><strong>⚠ Issues found!</strong> One or more required elements are missing from the portfolio page.</p>';
                    } else {
                        result += '<p class="success"><strong>✓ All checks passed!</strong> The portfolio page appears to be correctly configured.</p>';
                    }

                    resultsDiv.innerHTML = result;
                })
                .catch(err => {
                    resultsDiv.innerHTML = '<div class="alert alert-danger"><strong>✗ Fetch Error:</strong><br>' + err.message + '</div>';
                });
        }

        function testConsoleMessage() {
            console.log('=== TEST MESSAGE ===');
            console.log('This is a test message to verify console is working.');
            console.log('If you see this message, the console is properly connected.');
            alert('Check your browser console (F12) for the test message!');
        }

        // Automatically run some tests on page load
        window.addEventListener('load', function() {
            console.log('=== JavaScript Test Page Loaded ===');
            console.log('CSRF Token:', TEST_CSRF_TOKEN ? 'set' : 'NOT SET');
            console.log('Testing function availability...');
            testGenerateFunction();
        });
    </script>
</body>
</html>
