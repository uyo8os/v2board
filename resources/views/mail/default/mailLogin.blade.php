<div style="background: #f8fafc; padding: 50px 20px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">
    <div style="max-width: 550px; margin: 0 auto; background-color: #ffffff; border-radius: 16px; overflow: hidden; box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05); border: 1px solid #e2e8f0;">
        
        <div style="padding: 30px 40px; text-align: center; border-bottom: 1px solid #f1f5f9;">
            <h1 style="margin: 0; font-size: 20px; color: #1e293b; font-weight: 600; letter-spacing: -0.5px;">{{$name}}</h1>
        </div>
        
        
        <div style="padding: 40px;">
            <div style="text-align: center; margin-bottom: 30px;">
                <div style="width: 64px; height: 64px; background: #eff6ff; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center;">
                    <span style="font-size: 32px; color: #3b82f6;">✓</span>
                </div>
                <h2 style="margin: 20px 0 0; font-size: 22px; color: #1e293b; font-weight: 600;">登录验证</h2>
            </div>
            
            <p style="margin: 0 0 25px; font-size: 15px; color: #475569; line-height: 1.7; text-align: center;">
                我们检测到新的登录请求，请点击下方按钮完成验证。
            </p>
            
            <div style="margin: 30px 0; text-align: center;">
                <a href="{{$link}}" style="display: inline-block; padding: 12px 32px; background: #3b82f6; color: #ffffff; text-decoration: none; border-radius: 8px; font-size: 15px; font-weight: 500; transition: background 0.2s;">
                    确认登录
                </a>
            </div>
            
            <div style="margin: 40px 0 0; padding-top: 20px; text-align: center; font-size: 13px; color: #94a3b8;">
                此链接将在 <span style="color: #475569; font-weight: 500;">5 分钟</span> 后失效，如非本人操作，请忽略此邮件。
            </div>
        </div>
        
        
        <div style="padding: 20px; background-color: #f8fafc; text-align: center; border-top: 1px solid #f1f5f9;">
            <p style="margin: 0; font-size: 12px; color: #94a3b8;">
                此邮件由系统自动发送，请勿直接回复
            </p>
        </div>
    </div>
</div>
