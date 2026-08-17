// Auth Center 登录完整示例（Go 标准库 net/http）
//
// 运行：go run main.go（需要把 authcenter 包放到同目录或 go.mod 引用）
// 访问：http://127.0.0.1:8080/login 发起登录
//
// 依赖：github.com/gorilla/sessions（session 存储）
// 安装：go get github.com/gorilla/sessions
package main

import (
	"crypto/rand"
	"encoding/hex"
	"fmt"
	"html"
	"log"
	"net/http"

	"github.com/gorilla/sessions"

	"authcenter" // 改成你的模块路径，或直接 import "path/to/authcenter"
)

// ====== 配置（改成你的） ======
var sdk = authcenter.New(authcenter.Config{
	ClientID:     "你的client_id",
	ClientSecret: "***",
	RedirectURI:  "http://127.0.0.1:8080/callback",
})

const scope = "basic"

var store = sessions.NewCookieStore([]byte("换成你的随机密钥"))

// ① 登录入口
func handleLogin(w http.ResponseWriter, r *http.Request) {
	sess, _ := store.Get(r, "app-session")

	// 生成 state 存 session，回调时校验（防 CSRF）
	b := make([]byte, 16)
	rand.Read(b)
	state := hex.EncodeToString(b)
	sess.Values["oauth_state"] = state
	sess.Save(r, w)

	http.Redirect(w, r, sdk.GetAuthorizeURL(scope, state), http.StatusFound)
}

// ② 回调：校验 state → 换令牌 → 拿用户信息 → 创建登录会话
func handleCallback(w http.ResponseWriter, r *http.Request) {
	sess, _ := store.Get(r, "app-session")

	// 校验 state（防 CSRF）
	if r.URL.Query().Get("state") != sess.Values["oauth_state"] {
		http.Error(w, "state 校验失败，请重新发起登录", http.StatusBadRequest)
		return
	}

	// 用户拒绝授权
	if err := r.URL.Query().Get("error"); err != "" {
		desc := r.URL.Query().Get("error_description")
		if desc == "" {
			desc = err
		}
		http.Error(w, "授权失败："+desc, http.StatusBadRequest)
		return
	}

	code := r.URL.Query().Get("code")
	if code == "" {
		http.Error(w, "缺少授权码", http.StatusBadRequest)
		return
	}

	// 换令牌
	tokens, err := sdk.ExchangeCode(code)
	if err != nil {
		http.Error(w, "登录失败："+err.Error(), http.StatusInternalServerError)
		return
	}

	// 拿用户信息
	user, err := sdk.GetUserInfo(tokens.AccessToken)
	if err != nil {
		http.Error(w, "登录失败："+err.Error(), http.StatusInternalServerError)
		return
	}

	// ===== 在这里创建你自己的登录会话 =====
	// 用 user.ID 关联你的用户体系，tokens 存数据库
	sess.Values["logged_in"] = true
	sess.Values["nickname"] = user.Nickname
	sess.Save(r, w)

	fmt.Fprintf(w, "欢迎，%s！<a href=\"/\">进入首页</a>", html.EscapeString(user.Nickname))
}

// ③ 已登录页面（演示用）
func handleHome(w http.ResponseWriter, r *http.Request) {
	sess, _ := store.Get(r, "app-session")
	if sess.Values["logged_in"] != true {
		fmt.Fprint(w, `<a href="/login">使用 Auth Center 登录</a>`)
		return
	}
	fmt.Fprintf(w, "你好，%s！<a href=\"/logout\">退出</a>", html.EscapeString(fmt.Sprint(sess.Values["nickname"])))
}

func handleLogout(w http.ResponseWriter, r *http.Request) {
	sess, _ := store.Get(r, "app-session")
	sess.Options.MaxAge = -1
	sess.Save(r, w)
	http.Redirect(w, r, "/", http.StatusFound)
}

func main() {
	http.HandleFunc("/login", handleLogin)
	http.HandleFunc("/callback", handleCallback)
	http.HandleFunc("/logout", handleLogout)
	http.HandleFunc("/", handleHome)

	log.Println("示例已启动: http://127.0.0.1:8080/login")
	log.Fatal(http.ListenAndServe(":8080", nil))
}
