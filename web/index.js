$(function() {
	var thisPage = new ThisPage();
	thisPage.init();
});

function ThisPage(){
	this.userId = 0;
	this.socket = {};
	////test
	//this.msgCount = 0;
	//this.time1 = 0;
	var self = this;

	this.init = function(){
		var userId = self.getQueryString('id');
		self.userId = userId ? userId : -1;
		self.socket = new InfobirdWS('ws://127.0.0.1:9501');
		self.socket.bindOpen(self.onOpen);
		self.socket.bindMessage(self.onMsg);
		self.socket.bindClose(self.onClose);
		self.socket.bindError(self.onError);
		self.socket.connect();
	}

	this.bindSendMsg = function(){
		$('#send-btn').click(function(){
			var data = {content:$('#msg').val(),user_id:self.userId};
			var fullData = {event:'send_msg',data:data};
			console.log(fullData);
			self.socket.sendMessage(fullData);
			////test
			//var myDate1 = new Date();          //当前时间
			//var t1 = myDate1.getTime();
			//for(var i=0; i<1000; i++){
			//	self.socket.sendMessage(fullData);
			//}
			//var myDate2 = new Date();
			//var t2 = myDate2.getTime();
			//self.time1 = t1;
			//console.log(t2 - t1);
		});
	}

	this.onOpen = function(event){
		console.log("ws opened!");
		var data = {user_id:self.userId};
		var fullData = {event:'bind',data:data};
		//console.log(fullData);
		self.socket.sendMessage(fullData);
	}

	this.onMsg = function(event){
		console.log(event);
		if(event && event.data){
			var fullData = JSON.parse(event.data);
			if(fullData && fullData.event){
				switch (fullData.event){
					case 'online':
						self.execOnline(fullData.data);
						break;
					case 're_bind':
						if(fullData.code === 0){
							self.bindSendMsg();
						}else if(fullData.code === -2) {
							alert('没有该用户');
						}else{
							alert('登录失败');
						}
						break;
					case 're_msg':
						if(fullData.code !== 0){
							alert('消息发送失败');
						}
						break;
					case 'msg':
						////test
						//self.msgCount++;
						//console.log(self.msgCount);
						//if(self.msgCount == 1000){
						//	var myDate1 = new Date();          //当前时间
						//	var t1 = myDate1.getTime();
						//	console.log('total time:',t1 - self.time1);
						//}
						self.execMsg(fullData.data);
						break;
					case 'offline':
						self.execOffline(fullData.data);
						break;
				}
			}
		}
	}

	this.execOnline = function(data){
		var old = $('#msgArea').val();
		var content = data.user_name + '上线啦！';
		$('#msgArea').val(old + content + '\r\n');
	}

	this.execMsg = function(data){
		var old = $('#msgArea').val();
		var content = data.user_name + '：' + data.content + '。';
		$('#msgArea').val(old + content + '\r\n');
	}

	this.execOffline = function(data){
		var old = $('#msgArea').val();
		var content = data.user_name + '下线啦！';
		$('#msgArea').val(old + content + '\r\n');
	}

	this.onClose = function(event){
		console.log("ws closed.");
	}

	this.onError = function(event){
		console.log("ws error.");
	}

	this.getQueryString = 	function (name) {//获取url参数
		var reg = new RegExp("(^|&)" + name + "=([^&]*)(&|$)", "i");
		var r = window.location.search.substr(1).match(reg);
		if (r != null) return unescape(r[2]); return null;
	}
}