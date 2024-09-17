const express = require('express');
const bodyParser = require('body-parser');
const axios = require('axios');
const crypto = require('crypto');
const app = express();

// 使用 body-parser 解析 JSON 請求
app.use(bodyParser.json());

// 設定 Loyverse API 的存取權杖
const LOYVERSE_API_TOKEN = '12345678901234567890123456789012'; 

// 設定 ezPay 的商店代號和密鑰
const MERCHANT_ID = '11223344'; // 請替換為你的商店代號
const SECRET_KEY = '12345678901234567890123456789012'; // 請替換為你的密鑰
const IV = '1234567890123456'; // 請替換為你的IV

function addPadding(str) {
	const blockSize = 32;
	const padLength = blockSize - (str.length % blockSize);
	const padding = String.fromCharCode(padLength).repeat(padLength);
	return str + padding;
}

// Webhook 接收 Loyverse 訂單的路由
app.post('/webhook/loyverse', async (req, res) => {
    try {
        // 從 Loyverse 收到的訂單資料
        const orderData = req.body;
		const receipt = orderData.receipts[0]

        // 假設需要的資料
        const orderItems = receipt.line_items;  // 所有商品
        const totalAmount = receipt.total_money; // 總金額
		const totalTax = receipt.total_tax; // 總稅金
		
		if (receipt.customer_id !== null){
			console.log('You cannot create B2B invoice!!!!');
			return res.status(400).send('You cannot create B2B invoice!!!!');
		}
		var CarrierNum = null;
		var CarrierType = null;
		
		orderItems.forEach(function(item, index) {
		if (item.item_name == "@發票載具") {
			CarrierNum = item.line_note;
			CarrierType = 0;
			orderItems.splice(index, 1);
		}
		else if (item.item_name == "@ez載具") {
			CarrierNum = item.line_note;
			CarrierType = 2;
			orderItems.splice(index, 1);
		}});
		
		if (CarrierNum === null){
			console.log('Where is your 載具?????!!!!');
			return res.status(400).send('Where is your 載具?????!!!!');
		}
        // 構建發票資料，根據 ezPay API 規範
        const invoiceData = {
			RespondType: "JSON",
			Version: 1.5,
			TimeStamp: new Date(receipt.created_at).getTime() / 1000,
			MerchantOrderNo: receipt.receipt_number,   // 訂單號
			Status: 1,
			Category: "B2C",
			BuyerName: "Customer",
			BuyerEmail: CarrierType == 2 ? CarrierNum : null,
			CarrierNum: encodeURIComponent(CarrierNum),
			CarrierType: CarrierType,
			PrintFlag: "N",
			KioskPrintFlag: CarrierType == 2 ? 1 : null,
			TaxType: 1,
			TaxRate: 5,
			Amt: totalAmount - totalTax,
			TaxAmt: totalTax,
			TotalAmt: totalAmount,
			ItemName: orderItems.map(item => item.item_name).join('|'), // 商品名稱
			ItemCount: orderItems.map(item => item.quantity).join('|'), // 數量
			ItemPrice: orderItems.map(item => item.price).join('|'), // 價格
			ItemUnit: Array(orderItems.length).fill("個").join("|"),
			ItemAmt: orderItems.map(item => item.total_money).join('|'),
        };
		
		console.log(invoiceData);

		// Convert the object to a URL-encoded string, like PHP's http_build_query
		const postDataStr = new URLSearchParams(invoiceData).toString();

		// Add padding to the postDataStr
		const paddedData = addPadding(postDataStr);

		// Encrypt the data using AES-256-CBC
		const cipher = crypto.createCipheriv('aes-256-cbc', Buffer.from(SECRET_KEY), Buffer.from(IV));
		let encrypted = cipher.update(paddedData, 'utf8', 'hex');
		encrypted += cipher.final('hex');
		
		const transactionData = {
			MerchantID_: MERCHANT_ID,
            PostData_: encrypted
		}
		const transactionDataStr = new URLSearchParams(transactionData).toString();
	

        // 發送請求至 ezPay 開立發票
        const response = await axios.post('https://cinv.ezpay.com.tw/Api/invoice_issue', transactionDataStr, {
    headers: {
        'Content-Type': 'application/x-www-form-urlencoded'
    }
});

        // 處理成功回應
        if (response.data.Status === 'SUCCESS') {
            console.log('發票開立成功', response.data);
        } else {
            console.error('發票開立失敗', response.data);
        }

        // 回應 Loyverse Webhook 請求
        return res.status(200).send('Webhook received and processed');
    } catch (error) {
        console.error('錯誤:', error.message);
        console.error('錯誤詳情:', error);
        return res.status(500).send('Internal Server Error');
    }
});

// 啟動伺服器
app.listen(8888, () => {
    console.log('Webhook server is running on port 8888');
});
