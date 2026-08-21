<?php

function calculateDiscount(float $price, float $discount): float
{
    return $price - ($price * $discount / 100);
}

// Test 1
$result = calculateDiscount(100, 20);

if ($result === 80.0) {
    echo "Test 1 PASSED\n";
} else {
    echo "Test 1 FAILED: Expected 80, got $result\n";
}

// Test 2
$result = calculateDiscount(200, 10);

if ($result === 180.0) {
    echo "Test 2 PASSED\n";
} else {
    echo "Test 2 FAILED: Expected 180, got $result\n";
}

// Test 3
$result = calculateDiscount(500, 0);

if ($result === 500.0) {
    echo "Test 3 PASSED\n";
} else {
    echo "Test 3 FAILED: Expected 500, got $result\n";
}
