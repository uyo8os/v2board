<div style="background: #fff; padding: 50px 20px; font-family: Arial, sans-serif;">
    <table width="600" border="0" align="center" cellpadding="0" cellspacing="0" style="background: #141c2b; border-radius: 12px; overflow: hidden; box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);">
        <tbody>
            <tr>
                <td>
                    
                    <div style="background: #141c2b; border-bottom: 1px solid rgba(255, 255, 255, 0.1); padding: 24px 40px; text-align: center; color: #fff; font-size: 22px; font-weight: bold;">
                        {{$name}}
                    </div>

                    
                    <div style="padding: 40px; color: #c2c9d6;">
                        <h2 style="margin: 0 0 20px; font-size: 24px; color: #ffffff; text-align: center;">📢 网站通知</h2>
                        
                        <p style="margin: 0; font-size: 15px; line-height: 1.7; text-align: center;">
                            尊敬的用户您好！
                        </p>

                        <p style="margin: 20px 0 0; font-size: 15px; line-height: 1.7; color: #a9b3c2; text-align: center;">
                            {!! nl2br($content) !!}
                        </p>

                        
                        <div style="margin-top: 30px; text-align: center;">
                            <a href="{{$url}}" style="display: inline-block; padding: 14px 36px; background: linear-gradient(135deg, #00e5ff 0%, #0077ff 100%); color: #ffffff; text-decoration: none; border-radius: 8px; font-size: 16px; font-weight: 500; transition: all 0.2s; box-shadow: 0 4px 20px rgba(0, 229, 255, 0.4);">
                                🔗 返回 {{$name}}
                            </a>
                        </div>
                    </div>

                    
                    <div style="padding: 15px; background-color: rgba(255, 255, 255, 0.05); text-align: center;">
                        <p style="margin: 0; font-size: 12px; color: #8792a2;">
                            🔒 这是来自 {{$name}} 的重要通知
                        </p>
                    </div>
                </td>
            </tr>
        </tbody>
    </table>
</div>
