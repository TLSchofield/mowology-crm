<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class AccountingExpenseFilterTest extends TestCase
{
    public function test_expense_id_filter_matches_the_requested_receipt(): void
    {
        [$service, $executions] = $this->serviceWithRows([
            ['id' => 41, 'reference_type' => 'expense', 'reference_id' => 17],
        ]);

        $result = $service->listTransactions(['expense_id' => 17]);

        $this->assertSame(17, $result['data'][0]['reference_id']);
        $this->assertSame([17], $executions[0]);
        $this->assertSame([17, 50, 0], $executions[1]);
    }

    public function test_expense_id_filter_returns_no_rows_when_receipt_is_not_posted(): void
    {
        [$service, $executions] = $this->serviceWithRows([]);

        $result = $service->listTransactions(['expense_id' => 999]);

        $this->assertSame([], $result['data']);
        $this->assertSame([999], $executions[0]);
        $this->assertSame(0, $result['total']);
    }

    public function test_non_numeric_expense_id_is_ignored(): void
    {
        [$service, $executions] = $this->serviceWithRows([
            ['id' => 41, 'reference_type' => 'expense', 'reference_id' => 17],
        ]);

        $result = $service->listTransactions(['expense_id' => 'not-a-number']);

        $this->assertCount(1, $result['data']);
        $this->assertSame([], $executions[0]);
        $this->assertSame([50, 0], $executions[1]);
    }

    private function serviceWithRows(array $rows): array
    {
        $executions = new ArrayObject();
        $countStatement = $this->createMock(PDOStatement::class);
        $countStatement->method('execute')->willReturnCallback(function (array $params) use ($executions): bool {
            $executions->append($params);
            return true;
        });
        $countStatement->method('fetchColumn')->willReturn(count($rows));

        $listStatement = $this->createMock(PDOStatement::class);
        $listStatement->method('execute')->willReturnCallback(function (array $params) use ($executions): bool {
            $executions->append($params);
            return true;
        });
        $listStatement->method('fetchAll')->willReturn($rows);

        $db = $this->createMock(PDO::class);
        $db->expects($this->exactly(2))
            ->method('prepare')
            ->willReturnOnConsecutiveCalls($countStatement, $listStatement);

        return [new AccountingService($db), $executions];
    }
}
