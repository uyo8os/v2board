<?php

namespace Tests\Unit;

use App\Utils\SqlFile;
use PHPUnit\Framework\TestCase;

class SqlFileTest extends TestCase
{
    public function testQuotedCommentsAndStoredProceduresAreNotSplit(): void
    {
        $sql = <<<'SQL'
-- header; ignored
INSERT INTO example VALUES ('a;--b', 'it''s', "c;d");
/* another ; comment */
DELIMITER $$
CREATE PROCEDURE example() BEGIN SELECT 'x;y'; SELECT 2; END $$
DELIMITER ;
CALL example();
SQL;
        $statements = SqlFile::statements($sql);
        $this->assertCount(3, $statements);
        $this->assertSame("INSERT INTO example VALUES ('a;--b', 'it''s', \"c;d\")", $statements[0]);
        $this->assertSame("CREATE PROCEDURE example() BEGIN SELECT 'x;y'; SELECT 2; END", $statements[1]);
        $this->assertSame('CALL example()', $statements[2]);
    }

    public function testTruncatedSqlFailsBeforeImport(): void
    {
        $this->expectException(\RuntimeException::class);
        SqlFile::statements("SELECT 'unfinished;");
    }
}
