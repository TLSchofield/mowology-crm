<?php

class ProductQuickAddService
{
    private PDO $db;
    /** @var array<string,bool> */
    private array $columnCache = [];

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function formOptions(): array
    {
        try {
            $categories = $this->db->query('SELECT id, name FROM product_categories ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
            $units = $this->db->query('SELECT id, name, abbreviation FROM unit_types ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
            foreach ($categories as &$category) {
                $category['id'] = (int)$category['id'];
            }
            unset($category);
            foreach ($units as &$unit) {
                $unit['id'] = (int)$unit['id'];
            }
            unset($unit);
            return ['categories' => $categories, 'units' => $units];
        } catch (Throwable $e) {
            error_log('ProductQuickAddService::formOptions failed: ' . $e->getMessage());
            return ['categories' => [], 'units' => []];
        }
    }

    public function create(array $in, ?int $userId = null): array
    {
        try {
            $name = preg_replace('/\s+/u', ' ', trim((string)($in['name'] ?? '')));
            if ($name === '') {
                return ['ok' => false, 'message' => 'A service needs a name.'];
            }
            $name = mb_substr($name, 0, 255);

            $priceInput = $in['price'] ?? null;
            if ($priceInput === null || $priceInput === '' || !is_numeric($priceInput) || (float)$priceInput < 0) {
                return ['ok' => false, 'message' => 'Enter a price of 0 or more.'];
            }
            $price = round((float)$priceInput, 2);
            $categoryId = $this->optionalId($in['category_id'] ?? null);
            $unitTypeId = $this->optionalId($in['unit_type_id'] ?? null);

            if ($categoryId !== null && !$this->exists('product_categories', $categoryId)) {
                return ['ok' => false, 'message' => 'That category no longer exists.'];
            }
            if ($unitTypeId !== null && !$this->exists('unit_types', $unitTypeId)) {
                return ['ok' => false, 'message' => 'That unit no longer exists.'];
            }

            $description = trim((string)($in['description'] ?? ''));
            $description = $description === '' ? null : mb_substr($description, 0, 2000);

            // Compare with whitespace collapsed and case folded on BOTH sides, so a stored
            // "Garden   Care" still blocks "garden care". The catalog is small; read it once.
            $wanted = mb_strtolower($name);
            $existingId = false;
            foreach ($this->db->query('SELECT id, name FROM products WHERE is_archived = 0')->fetchAll(PDO::FETCH_ASSOC) as $p) {
                if (mb_strtolower(preg_replace('/\s+/u', ' ', trim((string)$p['name']))) === $wanted) {
                    $existingId = (int)$p['id'];
                    break;
                }
            }
            if ($existingId !== false) {
                return [
                    'ok' => false,
                    'duplicate' => true,
                    'item' => $this->catalogRow((int)$existingId),
                    'message' => '"' . $name . '" is already a service.',
                ];
            }

            $columns = ['name', 'description', 'base_price', 'category_id', 'unit_type_id', 'is_archived'];
            $values = [$name, $description, $price, $categoryId, $unitTypeId, 0];
            // Optional columns, written only where they exist — the same set QboOnboardingService
            // gives a new service (taxable: services carry GST; created_by: who added it).
            if ($this->hasColumn('active')) {
                $columns[] = 'active';
                $values[] = 1;
            }
            if ($this->hasColumn('taxable')) {
                $columns[] = 'taxable';
                $values[] = 1;
            }
            if ($userId !== null && $userId > 0 && $this->hasColumn('created_by')) {
                $columns[] = 'created_by';
                $values[] = $userId;
            }
            $placeholders = implode(', ', array_fill(0, count($columns), '?'));
            $stmt = $this->db->prepare('INSERT INTO products (' . implode(', ', $columns) . ') VALUES (' . $placeholders . ')');
            $stmt->execute($values);
            $id = (int)$this->db->lastInsertId();

            return [
                'ok' => true,
                'item' => $this->catalogRow($id),
                'message' => 'Added "' . $name . '" to services.',
            ];
        } catch (Throwable $e) {
            error_log('ProductQuickAddService::create failed: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Could not save that service.'];
        }
    }

    private function optionalId($value): ?int
    {
        if ($value === null || $value === '' || $value === 0 || $value === '0') {
            return null;
        }
        if ((is_int($value) && $value > 0) || (is_string($value) && preg_match('/^[1-9]\d*$/', $value))) {
            return (int)$value;
        }
        return -1;
    }

    private function exists(string $table, int $id): bool
    {
        $stmt = $this->db->prepare('SELECT id FROM ' . $table . ' WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        return $stmt->fetchColumn() !== false;
    }

    private function hasColumn(string $column): bool
    {
        if (!preg_match('/^[a-z_]+$/', $column)) {
            return false;
        }
        if (!array_key_exists($column, $this->columnCache)) {
            try {
                $this->db->query('SELECT ' . $column . ' FROM products LIMIT 0');
                $this->columnCache[$column] = true;
            } catch (Throwable $e) {
                $this->columnCache[$column] = false;
            }
        }
        return $this->columnCache[$column];
    }

    private function catalogRow(int $id): array
    {
        $stmt = $this->db->prepare("\n            SELECT p.id, p.name, p.description, p.base_price,\n                   c.name AS category_name, u.abbreviation AS unit_abbreviation\n            FROM products p\n            LEFT JOIN product_categories c ON p.category_id = c.id\n            LEFT JOIN unit_types u ON p.unit_type_id = u.id\n            WHERE p.id = ?\n        ");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $row['id'] = (int)$row['id'];
        return $row;
    }
}
