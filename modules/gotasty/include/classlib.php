<?php 

	include_once('class.msgpack.php');
    include_once('config.php');
    include_once('class.admin.php');
    include_once('class.database.php');
    include_once('class.cash.php');
    include_once('class.webservice.php');
    include_once('class.outgoing_webservices.php');
    include_once('class.user.php');
    include_once('class.api.php');
    include_once('class.message.php');
    include_once('class.permission.php');
    include_once('class.setting.php');
    include_once('class.language.php');
    include_once('class.provider.php');
    include_once('class.country.php');
    include_once('class.general.php');
    include_once('class.tree.php');
    include_once('class.activity.php');
    include_once('class.invoice.php');
    include_once('class.product.php');
    include_once('class.client.php');
    include_once('class.bulletin.php');
    include_once('class.bonus.php');
    include_once('PHPExcel.php');
    include_once('class.log.php');
    include_once('class.report.php');
    include_once('class.dashboard.php');
    include_once('class.ticket.php');
    include_once('class.otp.php');
    include_once('class.cryptoPG.php');
    include_once('class.leader.php');
    include_once('class.validation.php');
    include_once('class.wallet.php');
    include_once('class.queue.php');
    include_once('class.excel.php');
    include_once('class.subscribe.php');
    include_once('class.mall.php');
    include_once('class.bonusReport.php');
    include_once('class.custom.php');
    include_once('class.batch.php');
    include_once('class.inventory.php');
    include_once('class.P2P.php');
    include_once('class.trading.php');
    include_once('class.game.php');
    include_once('class.flutter.php');
    include_once('doSpaces/aws/autoloader.php');
    include_once('class.provider.aws.php');
    include_once('libphonenumber-for-php-master-v7.0/vendor/autoload.php');

    $db = new MysqliDb($config['dBHost'], $config['dBUser'], $config['dBPassword'], $config['dB']);
    
    Setting::setupSysSetting($config);
    Cash::setPaymentCredit();

?>
