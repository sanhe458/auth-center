// Package authcenter 是 Auth Center 统一登录的 Go SDK（零第三方依赖）
//
// 用法：
//   sdk := authcenter.New(authcenter.Config{
//       ClientID:     "你的client_id",
//       ClientSecret: "你的client_secret",
//       RedirectURI:  "https://yourapp.com/callback",
//   })
//
//   // 1. 生成授权链接
//   url := sdk.GetAuthorizeURL("basic", state)
//
//   // 2. 回调里换令牌
//   tokens, err := sdk.ExchangeCode(code)
//
//   // 3. 获取用户信息
//   user, err := sdk.GetUserInfo(tokens.AccessToken)
//
//   // 4. 刷新令牌
//   tokens, err = sdk.RefreshToken(tokens.RefreshToken)
package authcenter

import (
	"bytes"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"strings"
	"time"
)

const DefaultBaseURL = "https://auth.sanhe.com.mp"

// Config SDK 配置
type Config struct {
	ClientID     string
	ClientSecret string
	RedirectURI  string
	BaseURL      string // 可选，默认 DefaultBaseURL
	Timeout      time.Duration
}

// AuthCenter 客户端
type AuthCenter struct {
	baseURL      string
	clientID     string
	clientSecret string
	redirectURI  string
	http         *http.Client
}

// Tokens 令牌响应
type Tokens struct {
	AccessToken  string `json:"access_token"`
	TokenType    string `json:"token_type"`
	ExpiresIn    int    `json:"expires_in"`
	RefreshToken string `json:"refresh_token"`
	Scope        string `json:"scope"`
}

// User 用户信息
type User struct {
	ID        string `json:"id"`
	Nickname  string `json:"nickname"`
	Avatar    string `json:"avatar"`
	Email     string `json:"email"`
	CreatedAt string `json:"created_at"`
}

// Error API 错误
type Error struct {
	Code    int    `json:"code"`
	Message string `json:"message"`
}

func (e *Error) Error() string {
	return fmt.Sprintf("Auth Center 错误 [%d]: %s", e.Code, e.Message)
}

// New 创建 SDK 实例
func New(cfg Config) *AuthCenter {
	baseURL := cfg.BaseURL
	if baseURL == "" {
		baseURL = DefaultBaseURL
	}
	timeout := cfg.Timeout
	if timeout == 0 {
		timeout = 15 * time.Second
	}
	return &AuthCenter{
		baseURL:      strings.TrimRight(baseURL, "/"),
		clientID:     cfg.ClientID,
		clientSecret: cfg.ClientSecret,
		redirectURI:  cfg.RedirectURI,
		http:         &http.Client{Timeout: timeout},
	}
}

// GetAuthorizeURL 生成授权链接（引导用户跳转）
func (s *AuthCenter) GetAuthorizeURL(scope, state string) string {
	q := url.Values{}
	q.Set("response_type", "code")
	q.Set("client_id", s.clientID)
	q.Set("redirect_uri", s.redirectURI)
	q.Set("scope", scope)
	q.Set("state", state)
	return s.baseURL + "/api/oauth/authorize?" + q.Encode()
}

// ExchangeCode 授权码换令牌
func (s *AuthCenter) ExchangeCode(code string) (*Tokens, error) {
	var tokens Tokens
	err := s.post("/api/oauth/token", map[string]string{
		"grant_type":    "authorization_code",
		"code":          code,
		"client_id":     s.clientID,
		"client_secret": s.clientSecret,
		"redirect_uri":  s.redirectURI,
	}, &tokens)
	if err != nil {
		return nil, err
	}
	return &tokens, nil
}

// RefreshToken 刷新令牌
func (s *AuthCenter) RefreshToken(refreshToken string) (*Tokens, error) {
	var tokens Tokens
	err := s.post("/api/oauth/token", map[string]string{
		"grant_type":    "refresh_token",
		"refresh_token": refreshToken,
		"client_id":     s.clientID,
		"client_secret": s.clientSecret,
	}, &tokens)
	if err != nil {
		return nil, err
	}
	return &tokens, nil
}

// RevokeToken 吊销令牌
func (s *AuthCenter) RevokeToken(token string) error {
	return s.post("/api/oauth/revoke", map[string]string{"token": token}, nil)
}

// GetUserInfo 获取用户信息
func (s *AuthCenter) GetUserInfo(accessToken string) (*User, error) {
	var user User
	err := s.get("/api/info", accessToken, &user)
	if err != nil {
		return nil, err
	}
	return &user, nil
}

func (s *AuthCenter) get(path, token string, out any) error {
	req, err := http.NewRequest(http.MethodGet, s.baseURL+path, nil)
	if err != nil {
		return err
	}
	if token != "" {
		req.Header.Set("Authorization", "Bearer "+token)
	}
	return s.do(req, out)
}

func (s *AuthCenter) post(path string, body map[string]string, out any) error {
	b, _ := json.Marshal(body)
	req, err := http.NewRequest(http.MethodPost, s.baseURL+path, bytes.NewReader(b))
	if err != nil {
		return err
	}
	req.Header.Set("Content-Type", "application/json")
	return s.do(req, out)
}

func (s *AuthCenter) do(req *http.Request, out any) error {
	resp, err := s.http.Do(req)
	if err != nil {
		return err
	}
	defer resp.Body.Close()

	raw, err := io.ReadAll(resp.Body)
	if err != nil {
		return err
	}

	// 标准格式：非 2xx 视为失败，取 error/message 报错；成功直接解析顶层数据
	if resp.StatusCode < 200 || resp.StatusCode >= 300 {
		var e struct {
			Error   string `json:"error"`
			Message string `json:"message"`
			Code    int    `json:"code"`
		}
		_ = json.Unmarshal(raw, &e)
		msg := e.Error
		if msg == "" {
			msg = e.Message
		}
		if msg == "" {
			msg = fmt.Sprintf("请求失败 (HTTP %d)", resp.StatusCode)
		}
		return &Error{Code: e.Code, Message: msg}
	}
	if out != nil && len(raw) > 0 {
		return json.Unmarshal(raw, out)
	}
	return nil
}

// Err 便捷函数：把普通 error 转成 *Error（可选）
var Err = errors.New
