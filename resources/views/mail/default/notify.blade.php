<div style="background: #f8fafc; padding: 50px 20px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">
    <table width="600" border="0" align="center" cellpadding="0" cellspacing="0" style="background: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05); border: 1px solid #e2e8f0;">
        <tbody>
            <tr>
                <td>
                    
                    <div style="background: #ffffff; border-bottom: 1px solid #f1f5f9; padding: 24px 40px; text-align: center; color: #1e293b; font-size: 20px; font-weight: bold;">
                        {{$name}}
                    </div>

                    
                    <div style="padding: 40px; color: #475569;">
                        <h2 style="margin: 0 0 20px; font-size: 22px; color: #1e293b; text-align: center; font-weight: 600;">系统通知</h2>
                        
                        <p style="margin: 0; font-size: 15px; line-height: 1.7; text-align: center; color: #64748b;">
                            尊敬的用户您好：
                        </p>

                        <p style="margin: 20px 0 0; font-size: 15px; line-height: 1.8; color: #475569; text-align: center;">
                            {!! nl2br($content) !!}
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
