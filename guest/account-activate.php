<?php
require_once __DIR__.'/../includes/init.php';
$token=trim((string)($_GET['token']??$_POST['token']??''));$tokenRow=$_SESSION['activation_token_row']??null;$errors=[];
if($_SERVER['REQUEST_METHOD']==='GET'){$tokenRow=OrderAccessService::consume($conn,$token,'activate');if($tokenRow){$_SESSION['activation_token_row']=$tokenRow;}}
if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!verify_csrf()||!is_array($tokenRow)){$errors[]='This activation session expired.';}else{
        $password=(string)($_POST['password']??'');$confirm=(string)($_POST['confirm_password']??'');$passwordError=password_strength_error($password);
        if($passwordError!==null){$errors[]=$passwordError;}if($password!==$confirm){$errors[]='Passwords do not match.';}
        if(!$errors){
            $orderId=(int)$tokenRow['order_id'];$q=$conn->prepare("SELECT customer_name,customer_email,customer_phone,country FROM orders WHERE id=? LIMIT 1");$q->bind_param('i',$orderId);$q->execute();$order=$q->get_result()->fetch_assoc();
            $q=$conn->prepare("SELECT id FROM customers WHERE LOWER(TRIM(email))=LOWER(TRIM(?)) LIMIT 1");$q->bind_param('s',$order['customer_email']);$q->execute();
            if($q->get_result()->fetch_assoc()){$errors[]='An account already exists. Please use password reset.';}else{
                $hash=password_hash($password,PASSWORD_DEFAULT);$q=$conn->prepare("INSERT INTO customers(name,email,password_hash,phone,country,email_verified) VALUES(?,?,?,?,?,1)");$q->bind_param('sssss',$order['customer_name'],$order['customer_email'],$hash,$order['customer_phone'],$order['country']);$q->execute();$customerId=(int)$conn->insert_id;
                $q=$conn->prepare("UPDATE orders SET customer_id=? WHERE customer_id IS NULL AND LOWER(TRIM(customer_email))=LOWER(TRIM(?))");$q->bind_param('is',$customerId,$order['customer_email']);$q->execute();
                CartService::cart_save_to_db($conn,$customerId,$_SESSION['cart']??[],$_SESSION['cart_meter_length']??[]);wishlist_save_to_db($conn,$customerId,$_SESSION['wishlist']??[],$_SESSION['wishlist_meter_length']??[],$_SESSION['wishlist_size']??[]);
                unset($_SESSION['activation_token_row']);flash('success','Account activated. You can now log in.');redirect('/customer/login');
            }
        }
    }
}
$metaTitle=SiteContext::title('Activate Account');include __DIR__.'/../includes/header.php';?>
<section class="page-hero"><div class="container"><h1>Activate Account</h1></div></section><section class="section-block"><div class="container"><div class="surface-panel p-4 mx-auto" style="max-width:560px"><?php foreach($errors as $error):?><div class="alert alert-danger"><?php echo e($error);?></div><?php endforeach;?><?php if(is_array($tokenRow)):?><form method="post"><?php echo csrf_field();?><input type="hidden" name="token" value="<?php echo e($token);?>"><div class="mb-3"><label class="form-label">Password</label><input class="form-control" type="password" name="password" required autocomplete="new-password"></div><div class="mb-3"><label class="form-label">Confirm password</label><input class="form-control" type="password" name="confirm_password" required autocomplete="new-password"></div><button class="btn btn-primary w-100">Activate Account</button></form><?php else:?><p>This activation link is invalid or expired.</p><?php endif;?></div></div></section><?php include __DIR__.'/../includes/footer.php';?>
