<?php
// api/transactions.php - Update approve function

function approveTransaction($db, $id) {
    try {
        $db->beginTransaction();
        
        // Get transaction details
        $stmt = $db->prepare("SELECT * FROM transactions WHERE id = ? AND status = 'pending'");
        $stmt->execute([$id]);
        $transaction = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$transaction) {
            http_response_code(404);
            echo json_encode(['error' => 'Transaction not found or already processed']);
            return;
        }
        
        $type = $transaction['type'];
        $amount = $transaction['amount'];
        $memberId = $transaction['member_id'];
        $description = $transaction['description'] ?? '';
        
        // Update transaction status
        $stmt = $db->prepare("UPDATE transactions SET status = 'approved' WHERE id = ?");
        $stmt->execute([$id]);
        
        $updatedBalance = 0;
        $recipientBalance = 0;
        
        if ($type === 'deposit') {
            // Add to member's balance
            $stmt = $db->prepare("UPDATE members SET balance = balance + ? WHERE id = ?");
            $stmt->execute([$amount, $memberId]);
            error_log("Deposit: Added $amount to member $memberId");
            
            // Get updated balance
            $stmt = $db->prepare("SELECT balance FROM members WHERE id = ?");
            $stmt->execute([$memberId]);
            $member = $stmt->fetch(PDO::FETCH_ASSOC);
            $updatedBalance = $member['balance'];
            
        } elseif ($type === 'withdrawal') {
            // Subtract from member's balance
            $stmt = $db->prepare("UPDATE members SET balance = balance - ? WHERE id = ?");
            $stmt->execute([$amount, $memberId]);
            error_log("Withdrawal: Subtracted $amount from member $memberId");
            
            // Get updated balance
            $stmt = $db->prepare("SELECT balance FROM members WHERE id = ?");
            $stmt->execute([$memberId]);
            $member = $stmt->fetch(PDO::FETCH_ASSOC);
            $updatedBalance = $member['balance'];
            
        } elseif ($type === 'transfer') {
            // Find recipient ID from description
            $recipientId = null;
            
            // Try to find recipient ID in description (Format: "Transfer to [Name] (ID: [ID])")
            if (preg_match('/\(ID:\s*(\d+)\)/', $description, $matches)) {
                $recipientId = intval($matches[1]);
                error_log("Transfer: Found recipient ID from description: $recipientId");
            }
            
            if ($recipientId) {
                // Check if recipient exists
                $stmt = $db->prepare("SELECT id FROM members WHERE id = ?");
                $stmt->execute([$recipientId]);
                $recipient = $stmt->fetch();
                
                if ($recipient) {
                    // Subtract from sender
                    $stmt = $db->prepare("UPDATE members SET balance = balance - ? WHERE id = ?");
                    $stmt->execute([$amount, $memberId]);
                    error_log("Transfer: Subtracted $amount from sender $memberId");
                    
                    // Add to recipient
                    $stmt = $db->prepare("UPDATE members SET balance = balance + ? WHERE id = ?");
                    $stmt->execute([$amount, $recipientId]);
                    error_log("Transfer: Added $amount to recipient $recipientId");
                    
                    // Get updated sender balance
                    $stmt = $db->prepare("SELECT balance FROM members WHERE id = ?");
                    $stmt->execute([$memberId]);
                    $member = $stmt->fetch(PDO::FETCH_ASSOC);
                    $updatedBalance = $member['balance'];
                    
                    // Get updated recipient balance
                    $stmt = $db->prepare("SELECT balance FROM members WHERE id = ?");
                    $stmt->execute([$recipientId]);
                    $recipientMember = $stmt->fetch(PDO::FETCH_ASSOC);
                    $recipientBalance = $recipientMember['balance'];
                } else {
                    error_log("Transfer: Recipient ID $recipientId not found");
                }
            } else {
                // Fallback: try to find recipient by name
                if (preg_match('/Transfer\s+to\s+([^(]+)/', $description, $matches)) {
                    $recipientName = trim($matches[1]);
                    error_log("Transfer: Trying to find recipient by name: $recipientName");
                    
                    $stmt = $db->prepare("SELECT id FROM members WHERE name LIKE ?");
                    $stmt->execute(["%$recipientName%"]);
                    $recipient = $stmt->fetch();
                    
                    if ($recipient) {
                        $recipientId = $recipient['id'];
                        
                        // Subtract from sender
                        $stmt = $db->prepare("UPDATE members SET balance = balance - ? WHERE id = ?");
                        $stmt->execute([$amount, $memberId]);
                        error_log("Transfer: Subtracted $amount from sender $memberId");
                        
                        // Add to recipient
                        $stmt = $db->prepare("UPDATE members SET balance = balance + ? WHERE id = ?");
                        $stmt->execute([$amount, $recipientId]);
                        error_log("Transfer: Added $amount to recipient $recipientId");
                        
                        // Get updated sender balance
                        $stmt = $db->prepare("SELECT balance FROM members WHERE id = ?");
                        $stmt->execute([$memberId]);
                        $member = $stmt->fetch(PDO::FETCH_ASSOC);
                        $updatedBalance = $member['balance'];
                        
                        // Get updated recipient balance
                        $stmt = $db->prepare("SELECT balance FROM members WHERE id = ?");
                        $stmt->execute([$recipientId]);
                        $recipientMember = $stmt->fetch(PDO::FETCH_ASSOC);
                        $recipientBalance = $recipientMember['balance'];
                    } else {
                        error_log("Transfer: Could not find recipient by name: $recipientName");
                    }
                }
            }
        }
        
        $db->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'Transaction approved successfully',
            'sender_balance' => $updatedBalance,
            'recipient_balance' => $recipientBalance
        ]);
    } catch (Exception $e) {
        $db->rollBack();
        http_response_code(500);
        echo json_encode(['error' => 'Failed to approve transaction: ' . $e->getMessage()]);
    }
}
?>
