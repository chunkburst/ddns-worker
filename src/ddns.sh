#!/usr/bin/env bash
set -o errexit && set -o nounset && set -o pipefail

#配置参数
API_BASE_URL="ddns.xxxx.example"  #Worker主控地址
API_SECRET="apikey" #通信密钥,防止其他人随意调用API
SUBDOMAIN_PREFIX="hello" #域名前缀
CF_ZONE_NAME="autoccb.ccb" #主域名
RECORD_TYPE="A" #类型,V4写A,V6写AAAA
UPDATE_FREQUENCY="15" #更新频率,每多少分钟自动检测一次

# 自动检测IP
if [ "$RECORD_TYPE" = "A" ]; then
    WAN_IP=$(curl -fs http://ipv4.icanhazip.com)
elif [ "$RECORD_TYPE" = "AAAA" ]; then
    WAN_IP=$(curl -fs http://ipv6.icanhazip.com)
else
    echo "Invalid record type: $RECORD_TYPE. Must be A (IPv4) or AAAA (IPv6)"
    exit 1
fi

if [[ ! $WAN_IP =~ ^[0-9a-f.:]+$ ]]; then
    echo "Failed to get valid IP address. Got: $WAN_IP"
    exit 1
fi

#IP数据缓存,防止重复请求
LAST_IP_FILE="/tmp/cf-ddns-lastip-$SUBDOMAIN_PREFIX.txt"

if [ -f "$LAST_IP_FILE" ]; then
    LAST_IP=$(cat "$LAST_IP_FILE")
    if [ "$WAN_IP" = "$LAST_IP" ]; then
        echo "IP unchanged ($WAN_IP). No update needed."
        exit 0
    fi
fi

echo "Updating $SUBDOMAIN_PREFIX.$CF_ZONE_NAME to $WAN_IP..."

#尝试请求主控端
RESPONSE=$(curl -fsS -X POST "$API_BASE_URL" \
    -H "Authorization: Bearer $API_SECRET" \
    -H "Content-Type: application/json" \
    -d "{\"prefix\":\"$SUBDOMAIN_PREFIX\",\"ip\":\"$WAN_IP\",\"zone_name\":\"$CF_ZONE_NAME\",\"type\":\"$RECORD_TYPE\"}")


if echo "$RESPONSE" | grep -q '"success":true'; then
    echo "Update successful!"
    echo "$WAN_IP" > "$LAST_IP_FILE"

    SCRIPT_PATH=$(realpath "$0")
    CRON_JOB="*/$UPDATE_FREQUENCY * * * * $SCRIPT_PATH >/dev/null 2>&1"

    if ! crontab -l | grep -Fq "$SCRIPT_PATH"; then
    echo "Adding cron job..."
    (crontab -l 2>/dev/null; echo "$CRON_JOB") | crontab -

    if crontab -l | grep -Fq "$SCRIPT_PATH"; then
            echo "Cron job added successfully!"
            echo "Will run every $UPDATE_FREQUENCY minutes."
        else
            echo "Failed to add cron job. Please add manually:"
            echo "$CRON_JOB"
        fi
    fi

    exit 0
else
    echo "Update failed! Response:"
    echo "$RESPONSE"
    exit 1
fi
