<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/app/Core/RePrepareStatement.php';

final class RePrepareStatementTest extends TestCase
{
    public function testRecognisesOnly1615(): void
    {
        $e = new PDOException('SQLSTATE[HY000]: General error: 1615 Prepared statement needs to be re-prepared');
        $e->errorInfo = ['HY000', 1615, 'Prepared statement needs to be re-prepared'];
        $this->assertTrue(RePrepareStatement::isReprepare($e));
        $this->assertTrue(RePrepareStatement::isReprepare(new PDOException('SQLSTATE[HY000]: General error: 1615 Prepared statement needs to be re-prepared')));
        $this->assertFalse(RePrepareStatement::isReprepare(new PDOException('SQLSTATE[23000]: 1062 Duplicate entry')));
        $this->assertFalse(RePrepareStatement::isReprepare(new RuntimeException('1615 something else')));
    }

    public function testIntsStayIntsOnTheRetry(): void
    {
        $this->assertSame(PDO::PARAM_INT, RePrepareStatement::paramType(5), 'LIMIT ? keeps working emulated');
        $this->assertSame(PDO::PARAM_STR, RePrepareStatement::paramType('5'));
        $this->assertSame(PDO::PARAM_NULL, RePrepareStatement::paramType(null));
        $this->assertSame(PDO::PARAM_BOOL, RePrepareStatement::paramType(true));
    }

    public function testNormalStatementsBehaveAsBefore(): void
    {
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, ['RePrepareStatement', [$pdo]]);
        $pdo->exec('CREATE TABLE t (id INTEGER PRIMARY KEY, name TEXT)');
        $ins = $pdo->prepare('INSERT INTO t (name) VALUES (?)');
        $this->assertInstanceOf(RePrepareStatement::class, $ins);
        foreach (['a', 'b', 'c'] as $n) $ins->execute([$n]);
        $u = $pdo->prepare('UPDATE t SET name = ? WHERE id > ?');
        $u->execute(['z', 1]);
        $this->assertSame(2, $u->rowCount());
        $s = $pdo->prepare('SELECT id, name FROM t ORDER BY id LIMIT ?');
        $s->execute([2]);
        $this->assertSame([['id' => 1, 'name' => 'a'], ['id' => 2, 'name' => 'z']], $s->fetchAll());
        $s->execute([1]);
        $this->assertSame(['id' => 1, 'name' => 'a'], $s->fetch());
        $c = $pdo->prepare('SELECT COUNT(*) FROM t');
        $c->execute();
        $this->assertSame(3, (int)$c->fetchColumn());
        $k = $pdo->prepare('SELECT id, name FROM t');
        $k->execute();
        $this->assertSame([1 => 'a', 2 => 'z', 3 => 'z'], $k->fetchAll(PDO::FETCH_KEY_PAIR));
    }
}
