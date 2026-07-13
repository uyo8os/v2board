<div style="background: #f8fafc; padding: 50px 20px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">
    <table width="600" border="0" align="center" cellpadding="0" cellspacing="0" style="background: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05); border: 1px solid #e2e8f0;">
        <tbody>
            <tr>
                <td>
                    
                    <div style="background: #ffffff; padding: 24px 40px; border-bottom: 1px solid #f1f5f9; text-align: center; color: #1e293b; font-size: 20px; font-weight: bold;">
                        {{$name}}
                    </div>

                    
                    <div style="padding: 40px; color: #475569;">
                        <h2 style="margin: 0 0 20px; font-size: 22px; color: #1e293b; text-align: center; font-weight: 600;">验证您的邮箱</h2>
                        
                        <p style="margin: 0; font-size: 15px; line-height: 1.7; text-align: center; color: #64748b;">
                            尊敬的用户您好：
                        </p>

                        
                        <div style="margin: 30px 0; padding: 20px; background: #f1f5f9; border-radius: 8px; text-align: center;">
                            <span style="font-size: 32px; font-weight: bold; color: #3b82f6; letter-spacing: 4px;">{{$code}}</span>
                        </div>

                        <p style="margin: 10px 0 0; font-size: 14px; line-height: 1.7; color: #94a3b8; text-align: center;">
                            请在 <span style="color: #475569; font-weight: 500;">5 分钟</span> 内完成验证。如非本人操作，请忽略此邮件。
                        </p>

                        
                        <div style="margin-top: 32px; text-align: center;">
                            <a href="{{$url}}" style="display: inline-block; padding: 12px 32px; background: #3b82f6; color: #ffffff; text-decoration: none; border-radius: 8px; font-size: 15px; font-weight: 500; transition: background 0.2s;">
                                返回 {{$name}}
                            </a>
                        </div>
                    </div>

                    
                    <div style="padding: 20px; background-color: #f8fafc; text-align: center; border-top: 1px solid #f1f5f9;">
                        <p style="margin: 0; font-size: 12px; color: #94a3b8;">
                            此邮件由系统自动发送，请勿直接回复
                        </p>
                    </div>
                </td>
            </tr>
        </tbody>
    </table>
</div>
