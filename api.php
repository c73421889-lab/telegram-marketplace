<?php
/**
 * Telegram Marketplace API
 */

class MarketplaceAPI {
    protected $pdo;
    private $encryption;
    private $response_code = 200;
    
    public function __construct($pdo, $encryption) {
        $this->pdo = $pdo;
        $this->encryption = $encryption;
    }
    
    /**
     * Get user profile
     */
    public function get_user_profile($user_id) {
        $stmt = $this->pdo->prepare('\'
            SELECT u.*, w.main_balance, w.earnings_balance 
            FROM users u 
            LEFT JOIN wallets w ON u.id = w.user_id 
            WHERE u.id = ?
        ');
        $stmt->execute([$user_id]);
        return $stmt->fetch();
    }
    
    /**
     * Get wallet details
     */
    public function get_wallet($user_id) {
        $stmt = $this->pdo->prepare('\'
            SELECT * FROM wallets WHERE user_id = ?
        ');
        $stmt->execute([$user_id]);
        return $stmt->fetch();
    }
    
    /**
     * Get transaction history
     */
    public function get_transactions($user_id, $limit = 20, $offset = 0) {
        $stmt = $this->pdo->prepare('\'
            SELECT * FROM transactions 
            WHERE user_id = ? 
            ORDER BY created_at DESC 
            LIMIT ? OFFSET ?
        ');
        $stmt->execute([$user_id, $limit, $offset]);
        return $stmt->fetchAll();
    }
    
    /**
     * Get marketplace products
     */
    public function get_products($category = null, $search = null, $limit = 20, $offset = 0) {
        $query = 'SELECT p.*, u.first_name as seller_name, c.name as category_name 
                  FROM products p 
                  JOIN users u ON p.seller_id = u.id 
                  JOIN categories c ON p.category_id = c.id 
                  WHERE p.status = "approved" AND p.stock > 0';
        
        $params = [];
        
        if ($category) {
            $query .= ' AND p.category_id = ?';
            $params[] = $category;
        }
        
        if ($search) {
            $query .= ' AND p.title LIKE ?';
            $params[] = '%' . $search . '%';
        }
        
        $query .= ' ORDER BY p.is_featured DESC, p.created_at DESC LIMIT ? OFFSET ?';
        $params[] = $limit;
        $params[] = $offset;
        
        $stmt = $this->pdo->prepare($query);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
    
    /**
     * Get product details
     */
    public function get_product($product_id) {
        $stmt = $this->pdo->prepare('\'
            SELECT p.*, u.first_name as seller_name, u.is_verified, c.name as category_name 
            FROM products p 
            JOIN users u ON p.seller_id = u.id 
            JOIN categories c ON p.category_id = c.id 
            WHERE p.id = ? AND p.status = "approved"
        ');
        $stmt->execute([$product_id]);
        $product = $stmt->fetch();
        
        if ($product) {
            $product['reviews'] = $this->get_product_reviews($product_id);
        }
        
        return $product;
    }
    
    /**
     * Get product reviews
     */
    public function get_product_reviews($product_id) {
        $stmt = $this->pdo->prepare('\'
            SELECT r.*, u.first_name 
            FROM reviews r 
            JOIN users u ON r.buyer_id = u.id 
            WHERE r.product_id = ? 
            ORDER BY r.created_at DESC
        ');
        $stmt->execute([$product_id]);
        return $stmt->fetchAll();
    }
    
    /**
     * Create order
     */
    public function create_order($buyer_id, $product_id) {
        try {
            $this->pdo->beginTransaction();
            
            // Get product
            $stmt = $this->pdo->prepare('\'
            SELECT * FROM products WHERE id = ? AND stock > 0');
            $stmt->execute([$product_id]);
            $product = $stmt->fetch();
            
            if (!$product) {
                throw new Exception('Product not found or out of stock');
            }
            
            // Create order
            $order_number = 'ORD-' . time() . '-' . $buyer_id;
            $commission = $product['price'] * (DEFAULT_COMMISSION / 100);
            $seller_amount = $product['price'] - $commission;
            
            $stmt = $this->pdo->prepare('INSERT INTO orders (buyer_id, seller_id, product_id, order_number, amount, commission_amount, seller_amount, status, escrow_status) VALUES (?, ?, ?, ?, ?, ?, ?, "pending", "locked")');
            $stmt->execute([
                $buyer_id,
                $product['seller_id'],
                $product_id,
                $order_number,
                $product['price'],
                $commission,
                $seller_amount
            ]);
            
            $order_id = $this->pdo->lastInsertId();
            
            // Deduct stock
            $stmt = $this->pdo->prepare('UPDATE products SET stock = stock - 1, sales_count = sales_count + 1 WHERE id = ?');
            $stmt->execute([$product_id]);
            
            $this->pdo->commit();
            return $order_id;
            
        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }
    
    /**
     * Get user orders
     */
    public function get_user_orders($user_id, $type = 'buyer') {
        if ($type === 'buyer') {
            $stmt = $this->pdo->prepare('\'
                SELECT o.*, p.title as product_title, u.first_name as seller_name 
                FROM orders o 
                JOIN products p ON o.product_id = p.id 
                JOIN users u ON o.seller_id = u.id 
                WHERE o.buyer_id = ? 
                ORDER BY o.created_at DESC
            ');
        } else {
            $stmt = $this->pdo->prepare('\'
                SELECT o.*, p.title as product_title, u.first_name as buyer_name 
                FROM orders o 
                JOIN products p ON o.product_id = p.id 
                JOIN users u ON o.buyer_id = u.id 
                WHERE o.seller_id = ? 
                ORDER BY o.created_at DESC
            ');
        }
        
        $stmt->execute([$user_id]);
        return $stmt->fetchAll();
    }
    
    /**
     * Complete order and release escrow
     */
    public function complete_order($order_id, $buyer_id) {
        try {
            $this->pdo->beginTransaction();
            
            // Get order
            $stmt = $this->pdo->prepare('SELECT * FROM orders WHERE id = ? AND buyer_id = ?');
            $stmt->execute([$order_id, $buyer_id]);
            $order = $stmt->fetch();
            
            if (!$order || $order['status'] !== 'paid') {
                throw new Exception('Invalid order');
            }
            
            // Release escrow
            $stmt = $this->pdo->prepare('UPDATE orders SET status = "completed", delivery_status = "confirmed", escrow_status = "released", confirmed_at = NOW() WHERE id = ?');
            $stmt->execute([$order_id]);
            
            // Credit seller earnings
            $stmt = $this->pdo->prepare('UPDATE wallets SET earnings_balance = earnings_balance + ?, total_earned = total_earned + ? WHERE user_id = ?');
            $stmt->execute([$order['seller_amount'], $order['seller_amount'], $order['seller_id']]);
            
            // Log transaction
            $this->log_transaction($order['seller_id'], 'earnings', 'sale', $order['seller_amount'], $order_id, $order['product_id']);
            
            $this->pdo->commit();
            return true;
            
        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }
    
    /**
     * Log transaction
     */
    public function log_transaction($user_id, $wallet_type, $type, $amount, $order_id = null, $product_id = null) {
        $stmt = $this->pdo->prepare('INSERT INTO transactions (user_id, wallet_type, type, amount, order_id, product_id, status) VALUES (?, ?, ?, ?, ?, ?, "completed")');
        return $stmt->execute([$user_id, $wallet_type, $type, $amount, $order_id, $product_id]);
    }
    
    /**
     * Get categories
     */
    public function get_categories() {
        $stmt = $this->pdo->prepare('SELECT * FROM categories WHERE status = "active" ORDER BY name');
        $stmt->execute();
        return $stmt->fetchAll();
    }
    
    /**
     * Create payment request
     */
    public function create_payment_request($user_id, $amount, $payment_type = 'deposit', $order_id = null) {
        $reference = 'PAY-' . time() . '-' . $user_id;
        
        $stmt = $this->pdo->prepare('INSERT INTO payments (user_id, amount, payment_type, order_id, reference, status) VALUES (?, ?, ?, ?, ?, "pending")');
        $stmt->execute([$user_id, $amount, $payment_type, $order_id, $reference]);
        
        return $this->pdo->lastInsertId();
    }
    
    /**
     * Get user seller info
     */
    public function get_seller_info($user_id) {
        $stmt = $this->pdo->prepare('SELECT u.*, sp.name as plan_name, sp.product_limit, sp.commission_discount FROM users u LEFT JOIN seller_plans sp ON u.seller_plan_id = sp.id WHERE u.id = ? AND u.is_premium_seller = true');
        $stmt->execute([$user_id]);
        return $stmt->fetch();
    }
    
    /**
     * Get seller products
     */
    public function get_seller_products($seller_id) {
        $stmt = $this->pdo->prepare('SELECT p.*, c.name as category_name FROM products p JOIN categories c ON p.category_id = c.id WHERE p.seller_id = ? ORDER BY p.created_at DESC');
        $stmt->execute([$seller_id]);
        return $stmt->fetchAll();
    }
    
    /**
     * Send JSON response
     */
    public function send_response($data, $code = 200) {
        header('Content-Type: application/json');
        http_response_code($code);
        echo json_encode([
            'success' => $code >= 200 && $code < 300,
            'code' => $code,
            'data' => $data
        ]);
        exit;
    }
}

?>