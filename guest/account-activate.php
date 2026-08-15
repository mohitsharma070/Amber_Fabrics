<?php
require_once __DIR__.'/../includes/init.php';
$token=trim((string)($_GET['token']??''));$tokenRow=$_SESSION['activation_token_row']??null;$errors=[];
if(is_array($tokenRow)&&time()>(int)($_SESSION['activation_session_expires_at']??0)){unset($_SESSION['activation_token_row'],$_SESSION['activation_token_fingerprint'],$_SESSION['activation_session_expires_at']);$tokenRow=null;}
if($_SERVER['REQUEST_METHOD']==='GET'){
    $consumed=$token!==''?OrderAccessService::consume($conn,$token,'activate'):null;
    if($consumed){
        session_regenerate_id(true);
        $tokenRow=$consumed;
        $_SESSION['activation_token_row']=$consumed;
        $_SESSION['activation_token_fingerprint']=hash('sha256',$token);
        $_SESSION['activation_session_expires_at']=time()+1800;
    }elseif(!is_array($tokenRow)||$token===''||!hash_equals((string)($_SESSION['activation_token_fingerprint']??''),hash('sha256',$token))){
        $tokenRow=null;
    }
}
if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!verify_csrf()||!is_array($tokenRow)){$errors[]='This activation session expired.';}else{
        $password=(string)($_POST['password']??'');$confirm=(string)($_POST['confirm_password']??'');$passwordError=password_strength_error($password);
        if($passwordError!==null){$errors[]=$passwordError;}if($password!==$confirm){$errors[]='Passwords do not match.';}
        if(!$errors){
            $orderId=(int)$tokenRow['order_id'];
            $customerId=0;
            try{
                $conn->begin_transaction();
                $q=$conn->prepare("SELECT customer_name,customer_email,customer_phone,country FROM orders WHERE id=? LIMIT 1 FOR UPDATE");$q->bind_param('i',$orderId);$q->execute();$order=$q->get_result()->fetch_assoc();
                if(!$order||!filter_var((string)($order['customer_email']??''),FILTER_VALIDATE_EMAIL)){throw new RuntimeException('Activation order is unavailable.');}
                $normalizedEmail=strtolower(trim((string)$order['customer_email']));
                $q=$conn->prepare("SELECT id FROM customers WHERE LOWER(TRIM(email))=? LIMIT 1 FOR UPDATE");$q->bind_param('s',$normalizedEmail);$q->execute();
                if($q->get_result()->fetch_assoc()){
                    $conn->rollback();
                    $errors[]='An account already exists. Please use password reset.';
                }else{
                    $name=(string)$order['customer_name'];$phone=(string)$order['customer_phone'];$country=(string)$order['country'];$hash=password_hash($password,PASSWORD_DEFAULT);
                    $q=$conn->prepare("INSERT INTO customers(name,email,password_hash,phone,country,email_verified) VALUES(?,?,?,?,?,1)");$q->bind_param('sssss',$name,$normalizedEmail,$hash,$phone,$country);$q->execute();$customerId=(int)$conn->insert_id;
                    $q=$conn->prepare("UPDATE orders SET customer_id=? WHERE customer_id IS NULL AND LOWER(TRIM(customer_email))=?");$q->bind_param('is',$customerId,$normalizedEmail);$q->execute();
                    $conn->commit();
                }
            }catch(Throwable $e){
                try{$conn->rollback();}catch(Throwable $ignored){}
                error_log('[account-activate] '.$e->getMessage());
                $errors[]='Unable to activate the account right now. Please try again.';
            }
            if($customerId>0&&!$errors){
                CartService::cart_save_to_db($conn,$customerId,$_SESSION['cart']??[],$_SESSION['cart_meter_length']??[]);wishlist_save_to_db($conn,$customerId,$_SESSION['wishlist']??[],$_SESSION['wishlist_meter_length']??[],$_SESSION['wishlist_size']??[]);
                unset($_SESSION['activation_token_row'],$_SESSION['activation_token_fingerprint'],$_SESSION['activation_session_expires_at']);flash('success','Account activated. You can now log in.');redirect('/customer/login');
            }
        }
    }
}
$metaTitle=SiteContext::title('Activate Account');include __DIR__.'/../includes/header.php';?>
<section class="page-hero"><div class="container"><h1>Activate Account</h1></div></section><section class="section-block"><div class="container"><div class="surface-panel p-4 mx-auto" style="max-width:560px"><?php foreach($errors as $error):?><div class="alert alert-danger"><?php echo e($error);?></div><?php endforeach;?><?php if(is_array($tokenRow)):?><form method="post"><?php echo csrf_field();?><div class="mb-3"><label class="form-label">Password</label><input class="form-control" type="password" name="password" required autocomplete="new-password"></div><div class="mb-3"><label class="form-label">Confirm password</label><input class="form-control" type="password" name="confirm_password" required autocomplete="new-password"></div><button class="btn btn-primary w-100">Activate Account</button></form><?php else:?><p class="mb-3">This activation link is invalid or expired.</p><a class="btn btn-outline-primary" href="/guest/order-access">Manage your order</a><?php endif;?></div></div></section><?php include __DIR__.'/../includes/footer.php';?>
