<?php
declare(strict_types=1);
final class ToolUsageCounter {
 private PDO $db; public function __construct(PDO $db){$this->db=$db;}
 public function increment(string $tool,string $column='calcs'):void { if(!in_array($tool,ToolCalculator::tools(),true)||!in_array($column,['calcs','saves_confirmed'],true))return;$sql="INSERT INTO tool_usage_daily (tool,day,{$column}) VALUES (?,CURRENT_DATE,1) ON DUPLICATE KEY UPDATE {$column}={$column}+1";$this->db->prepare($sql)->execute([$tool]); }
}
