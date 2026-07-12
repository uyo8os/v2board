<div style="background: #fff; padding: 50px 20px; font-family: Arial, sans-serif;">
    <div style="max-width: 550px; margin: 0 auto; background-color: #141c2b; border-radius: 16px; overflow: hidden; box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);">
        
        <div style="padding: 30px 40px; text-align: center; border-bottom: 1px solid rgba(255, 255, 255, 0.1);">
            <h1 style="margin: 0; font-size: 24px; color: #ffffff; font-weight: 600; letter-spacing: -0.5px;">{{$name}}</h1>
        </div>
        
        
        <div style="padding: 40px;">
            <div style="text-align: center; margin-bottom: 30px;">
                <div style="width: 70px; height: 70px; background: linear-gradient(145deg, #1e2a3a, #131a26); border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; box-shadow: 0 4px 12px rgba(0, 255, 255, 0.2);">
                    <span style="font-size: 36px; color: #00e5ff;">✓</span>
                </div>
                <h2 style="margin: 20px 0 0; font-size: 22px; color: #ffffff; font-weight: 600;">登录验证</h2>
            </div>
            
            <p style="margin: 0 0 25px; font-size: 15px; color: #c2c9d6; line-height: 1.7; text-align: center;">
                我们检测到新的登录请求，请点击下方按钮完成验证。
            </p>
            
            <div style="margin: 30px 0; text-align: center;">
                <a href="{{$link}}" style="display: inline-block; padding: 14px 36px; background: linear-gradient(135deg, #00e5ff 0%, #0077ff 100%); color: #ffffff; text-decoration: none; border-radius: 8px; font-size: 16px; font-weight: 500; transition: all 0.2s; box-shadow: 0 4px 20px rgba(0, 229, 255, 0.4);">
                    ⚡ 确认登录
                </a>
            </div>
            
            <div style="margin: 40px 0 0; padding-top: 20px; text-align: center; font-size: 13px; color: #8992a3;">
                ⏳ 此链接将在 <b style="color: #ffffff;">5 分钟</b> 后失效，如非本人操作，请忽略此邮件。
            </div>
        </div>
        
        
        <div style="padding: 15px; background-color: rgba(255, 255, 255, 0.05); text-align: center;">
            <p style="margin: 0; font-size: 12px; color: #8792a2;">
                🔒 安全邮件提醒 · {{$name}}
            </p>
        </div>
    </div>
</div>
