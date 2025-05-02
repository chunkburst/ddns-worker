//环境变量(尽量都设置为secret模式)
//CF_API_KEY: CF的API key
//CF_API_EMAIL: CF注册邮箱
//DEFAULT_TTL: 默认TTL值 (可选，默认为1)(1在CF代表auto)
//API_SECRET: 用于客户端认证的密钥

//[CF_ZONE_ID]: Cloudflare 区域ID (可无视此项,会自动进行获取)

//TG通知部分(如果不想用/不知道这是什么可以无视)
//TG_BOT_TOKEN: BOT的token
//TG_CHANNEL_ID: 频道的ID,发送消息用

export default {
    async fetch(request, env){
        if(request.method !== 'POST') return new Response('Method Not Allowed', { status: 405 });

        const auth = request.headers.get('Authorization');
        if(!auth || auth !== `Bearer ${env.API_SECRET}`) return new Response('Unauthorized', { status: 501 });
  
        const { prefix, ip, type = 'A', ttl, zone_name } = await request.json();
        
        if(!prefix || !ip) return new Response('Bad Gateway: prefix and ip are required', { status: 502 });
        const recordType = (type.toUpperCase() === 'AAAA') ? 'AAAA' : 'A';
        
        const recordTTL = ttl || parseInt(env.DEFAULT_TTL) || 1;

        const fullRecordName = `${prefix}.${zone_name}`;

        let recordId = null;
        let zoneId = env.CF_ZONE_ID;
        if(zoneId == null){
            const Api = 'https://api.cloudflare.com/client/v4/zones?name=' + (zone_name);
            const response = await fetch(Api, {
                headers: {
                'X-Auth-Email': env.CF_API_EMAIL,
                'X-Auth-Key': env.CF_API_KEY,
                'Content-Type': 'application/json'
                }
            });
            const data = await response.json();
            zoneId = data.result[0].id;
        }

        const recordsUrl = `https://api.cloudflare.com/client/v4/zones/${zoneId}/dns_records?type=${recordType}&name=${encodeURIComponent(fullRecordName)}`;
        
        const recordsResponse = await fetch(recordsUrl, {
            headers: {
                'X-Auth-Email': env.CF_API_EMAIL,
                'X-Auth-Key': env.CF_API_KEY,
                'Content-Type': 'application/json'
            }
        });
  
        const recordsData = await recordsResponse.json();
        
        if(recordsData.success && recordsData.result.length > 0){
            recordId = recordsData.result[0].id;
        }

        const endpoint = recordId 
          ? `https://api.cloudflare.com/client/v4/zones/${zoneId}/dns_records/${recordId}`
          : `https://api.cloudflare.com/client/v4/zones/${zoneId}/dns_records`;
        
        const method = recordId ? 'PUT' : 'POST';
        
        const recordData = {
            type: recordType,
            name: fullRecordName,
            content: ip,
            ttl: recordTTL,
            proxied: false
        };
        
        if(recordId) recordData.id = recordId;
  
        const cfResponse = await fetch(endpoint, {
            method: method,
            headers: {
                'X-Auth-Email': env.CF_API_EMAIL,
                'X-Auth-Key': env.CF_API_KEY,
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(recordData)
        });
  
        const cfData = await cfResponse.json();

        if(!cfData.success)
            return new Response(JSON.stringify({
                success: false,
                errors: cfData.errors
            }), {
                status: 504,
                headers: { 'Content-Type': 'application/json' }
            });
        
        const action = recordId ? 'updated' : 'created';
        await sendTelegramNotification(env, action, prefix, ip);

        return new Response(JSON.stringify({
            success: true,
            action: recordId ? 'updated' : 'created',
            record: cfData.result
        }), {
            status: 200,
            headers: { 'Content-Type': 'application/json' }
        });
    }
}

async function sendTelegramNotification(env, action, recordName, ip) {
    if(action == 'updated') action = '更新';
    else action = '创建';
    const message = `🚀 CCB-DDNS
- 记录变更: ${action.toUpperCase()}
- 记录名称: ${recordName}
- 新 IP: ${ip}`;

    const telegramUrl = `https://api.telegram.org/bot${env.TG_BOT_TOKEN}/sendMessage`;
    const response = await fetch(telegramUrl, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        },
        body: JSON.stringify({
            chat_id: env.TG_CHANNEL_ID, 
            text: message,
            parse_mode: 'Markdown'
        })
    });

    const data = await response.json();
    if (!data.ok) {
        console.error('TG Error:', data);
    }
}